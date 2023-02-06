<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Maintenance\Traefik;

use DefaultValue\Dockerizer\Docker\Compose\Composition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Yosymfony\Toml\Toml;
use Yosymfony\Toml\TomlBuilder;

/**
 * @noinspection PhpUnused
 */
class CleanupCertificates extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'maintenance:traefik:cleanup-certificates';

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setDescription('Clean up SSL certificates')
            ->setHelp(<<<'EOF'
                Run <info>%command.name%</info> to remove SSL certificates from SSL_CERTIFICATES_DIR and DOCKERIZER_TRAEFIK_SSL_CONFIGURATION_FILE if they are not present in any "virtual-host.conf" file within PROJECTS_ROOT_DIR
                Use at your own responsibility. Generating new certificates with "mkcert" is not a big deal anyway.
            EOF);
        // phpcs:enable

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $certificates = Toml::parseFile($this->env->getTraefikSslConfigurationFile());

        if (
            !isset($certificates['tls']['certificates'])
            || !is_array($certificates['tls']['certificates'])
        ) {
            $output->writeln('No certificates found to cleanup');

            return self::SUCCESS;
        }

        // Collect all certificate file names used in all projects
        $knownSslCertificateFiles = [];
        $virtualHostFiles = Finder::create()
            ->in($this->env->getProjectsRootDir())
            ->path(Composition::DOCKERIZER_DIR)
            ->ignoreDotFiles(false)
            ->files()
            ->name('virtual-host.conf');

        foreach ($virtualHostFiles as $virtualHostFileInfo) {
            $output->writeln('Found virtual host file: ' . $virtualHostFileInfo->getRealPath());
            preg_match_all(
                '/\S+\.pem/i',
                $this->filesystem->fileGetContents($virtualHostFileInfo->getRealPath()),
                $matches
            );

            if (is_array($matches) && isset($matches[0])) {
                $knownSslCertificateFiles[] = $matches[0];
            }
        }

        $knownSslCertificateFiles = array_merge(...$knownSslCertificateFiles);
        array_walk($knownSslCertificateFiles, static function (string &$fileName) {
            $fileName = basename($fileName);
        });

        // Delete all certificate files not found in the projects
        $sslCertificateFiles = Finder::create()
            ->in($this->env->getSslCertificatesDir())
            ->files()
            ->notName($knownSslCertificateFiles);
        $output->writeln('Cleaning unneeded SSL certificates...');

        foreach ($sslCertificateFiles as $sslCertificateFileInfo) {
            $this->filesystem->remove($sslCertificateFileInfo->getRealPath());
        }

        $potentiallyRequiredSslCertificateFiles = Finder::create()
            ->in($this->env->getSslCertificatesDir())
            ->files();
        $sslCertificatesOnDisk = [];

        foreach ($potentiallyRequiredSslCertificateFiles as $potentiallyRequiredSslCertificateFileInfo) {
            $realpath = $potentiallyRequiredSslCertificateFileInfo->getRealPath();
            $sslCertificatesOnDisk[$realpath] = basename($realpath);
        }

        // Delete all records about the certificate files that do not exist
        $output->writeln('Cleaning Traefik configuration file...');
        $toml = new TomlBuilder(2);
        $toml->addTable('tls');

        foreach ($certificates['tls']['certificates'] as $index => $certificate) {
            if (
                in_array(basename($certificate['certFile']), $sslCertificatesOnDisk, true)
                && in_array(basename($certificate['keyFile']), $sslCertificatesOnDisk, true)
            ) {
                $toml->addArrayOfTable('tls.certificates')
                    ->addValue('certFile', $certificate['certFile'])
                    ->addValue('keyFile', $certificate['keyFile']);
            }

            unset($certificates['tls']['certificates'][$index]);
        }

        $this->filesystem->filePutContents($this->env->getTraefikSslConfigurationFile(), $toml->getTomlString());

        $output->writeln('Cleanup completed!');

        return self::SUCCESS;
    }
}
