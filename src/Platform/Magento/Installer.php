<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\Compose\CompositionFilesNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Install Magento
 *
 * Composition template and services myst be configured in the respective command (or web app if we ever get it)
 */
class Installer
{
    private const PHP_SERVICE_PREFIX = 'php-';

    private const MAGENTO_REPOSITORY = 'https://%s:%s@repo.magento.com/';

    private const MAGENTO_PROJECT = 'magento/project-community-edition';

    private const EXECUTION_TIMEOUT_MEDIUM = 300;

    private const EXECUTION_TIMEOUT_LONG = 3600;

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\Docker $docker
    ) {

    }

    /**
     * @TODO: split into several methods
     *
     * @param OutputInterface $output
     * @param string $magentoVersion
     * @param array $domains
     * @param bool $force
     * @return void
     * @throws \Exception
     */
    public function install(OutputInterface $output, string $magentoVersion, array $domains, bool $force)
    {
        // === Configure required directories ===
        $mainDomain = $domains[0];
        $projectRoot = $this->getProjectRoot($mainDomain);
        // Do this here in order to be sure we have these parameters
        $this->getAuthJson();

        try {
            // Prepare installation directory
            if (!$this->filesystem->isEmptyDir($projectRoot)) {
                if ($force) {
                    $output->writeln('Cleaning up the project directory...');
                    $this->cleanUp($projectRoot);
                    $this->filesystem->getDirPath($projectRoot);
                    // getcwd() return false after cleanup, because original dir is deleted
                    chdir($projectRoot);
                } else {
                    // Unset variable so that project files are not removed in this particular case
                    unset($mainDomain);
                    throw new InstallationDirectoryNotEmptyException(<<<EOF
                    Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
                    Stop all containers (if any), remove the folder and re-run setup.
                    You can also use '-f' option to force install Magento with this domain.
                    EOF);
                }
            }

            // === 1. Dockerize ===
            $output->writeln('Generating composition files and running it...');
            $webRoot = $this->composition->getParameterValue('web_root', true);
            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDirPath($projectRoot . ltrim($webRoot, '\\/'));
            // @TODO: must be done while dumping composition and processing virtual hosts file
            $this->filesystem->getDirPath($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');
            $modificationContext = $this->composition->dump($output, $projectRoot, false);
            $dockerComposeDir = $modificationContext->getDockerComposeDir();
            $dockerCompose = $this->dockerCompose->setCwd($dockerComposeDir);
            // just in case previous setup was not successful
            $dockerCompose->down();
            $dockerCompose->up();

            // Remove all Docker files so that the folder is empty
            $phpContainerName = $this->getPhpContainerName(
                $modificationContext->getCompositionYaml(),
                $dockerCompose
            );
            // Remove all Docker files so that the folder is empty
            $this->docker->exec('sh -c "find -delete"', $phpContainerName);

            // === 2. Create Magento project ===
            $process = $this->docker->exec('composer -V', $phpContainerName);
            $composerMeta = trim($process->getOutput(), '');
            $composerVersion = (int) preg_replace('/\D/', '', $composerMeta)[0];
            $authJson = $this->getAuthJson($composerVersion === 1 ? 1 : 2);

            $magentoRepositoryUrl = sprintf(
                self::MAGENTO_REPOSITORY,
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password']
            );
            $magentoCreateProject = sprintf(
                'composer create-project --repository=%s %s=%s /var/www/html',
                $magentoRepositoryUrl,
                self::MAGENTO_PROJECT,
                $magentoVersion
            );
            $output->writeln('Calling "composer create-project" to get project files...');
            $this->docker->exec($magentoCreateProject, $phpContainerName, self::EXECUTION_TIMEOUT_LONG);

            // Hotfix for Magento 2.4.1
            // @TODO: install 2.4.1 and test this, check patches
//            if (!file_exists("{$projectRoot}.gitignore")) {
//                $this->addGitignoreFrom240($projectRoot);
//            }


            $this->composition->dump($output, $projectRoot, false);
            $dockerCompose->down();




            $foo = false;
        } catch (InstallationDirectoryNotEmptyException|CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
//            $this->cleanUp($projectRoot);
            throw $e;
        }

        $output->writeln('Magento installation completed!');
    }

    /**
     * @param string $dir
     * @return string
     */
    public function getProjectRoot(string $dir): string
    {
        return $this->filesystem->getDirPath($dir);
    }

    /**
     * Not yet tested with special chars or some tricky encodings in the domain name
     *
     * @param Compose $dockerCompose
     * @return string
     */
    private function getPhpContainerName(array $compositionYaml, Compose $dockerCompose): string
    {
        foreach ($compositionYaml['services'] as $serviceName => $service) {
            if (str_starts_with($serviceName, self::PHP_SERVICE_PREFIX) && isset($service['container_name'])) {
                return $service['container_name'];
            }
        }

        // @TODO: to be implemented, leaving the code broken because we work with simple Apache composition first
        throw new \Exception('To be implemented');

        foreach ($dockerCompose->ps() as $containerData) {
            if (str_starts_with($containerName, $containerNameStartsWith)) {
                // return $containerName;
            }
        }

        throw new \RuntimeException(
            'Can\'t find a service running PHP (expecting service name starting with "php-")'
        );
    }

    /**
     * @param string $projectRoot
     * @return void
     */
    private function cleanUp(string $projectRoot): void
    {
        try {
            $dockerizerDir = $this->composition->getDockerizerDirInProject($projectRoot);

            // @TODO: do not do this recursively in all directories
            foreach (Finder::create()->in($dockerizerDir)->directories() as $dockerizerDir) {
                $dockerCompose = $this->dockerCompose->setCwd($dockerizerDir->getRealPath());

                try {
                    $dockerCompose->down();
                } catch (CompositionFilesNotFoundException $e) {
                    // Do nothing in case files are just missed
                }
            }

            $this->filesystem->remove($projectRoot);
        } catch (\Exception $e) {
            throw new CleanupException($e->getMessage());
        }
    }

    /**
     * @param int $composerVersion
     * @return array
     * @throws \JsonException
     */
    private function getAuthJson(int $composerVersion = 1): array
    {
        $authJson = $this->filesystem->getAuthJson();

        if (
            !isset(
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password'],
                $authJson['github-oauth']['github.com'],
            )
        ) {
            throw new \RuntimeException(
                'The file "auth.json" does not contain "username" or "password" for "repo.magento.com",' .
                ' and a GitHub key!'
            );
        }

        // if composer version === 1 - remove `ghp_` from the key
        if ($composerVersion === 1) {
            $authJson['github-oauth']['github.com'] = explode('_', $authJson['github-oauth']['github.com'])[1];
        }

        return $authJson;
    }

    /**
     * @param string $projectRoot
     */
    private function addGitignoreFrom240(string $projectRoot): void
    {
        file_put_contents(
            "{$projectRoot}.gitignore",
            <<<GITIGNORE
            /.buildpath
            /.cache
            /.metadata
            /.project
            /.settings
            /.vscode
            atlassian*
            /nbproject
            /robots.txt
            /pub/robots.txt
            /sitemap
            /sitemap.xml
            /pub/sitemap
            /pub/sitemap.xml
            /.idea
            /.gitattributes
            /app/config_sandbox
            /app/etc/config.php
            /app/etc/env.php
            /app/code/Magento/TestModule*
            /lib/internal/flex/uploader/.actionScriptProperties
            /lib/internal/flex/uploader/.flexProperties
            /lib/internal/flex/uploader/.project
            /lib/internal/flex/uploader/.settings
            /lib/internal/flex/varien/.actionScriptProperties
            /lib/internal/flex/varien/.flexLibProperties
            /lib/internal/flex/varien/.project
            /lib/internal/flex/varien/.settings
            /node_modules
            /.grunt
            /Gruntfile.js
            /package.json
            /.php_cs
            /.php_cs.cache
            /grunt-config.json
            /pub/media/*.*
            !/pub/media/.htaccess
            /pub/media/attribute/*
            !/pub/media/attribute/.htaccess
            /pub/media/analytics/*
            /pub/media/catalog/*
            !/pub/media/catalog/.htaccess
            /pub/media/customer/*
            !/pub/media/customer/.htaccess
            /pub/media/downloadable/*
            !/pub/media/downloadable/.htaccess
            /pub/media/favicon/*
            /pub/media/import/*
            !/pub/media/import/.htaccess
            /pub/media/logo/*
            /pub/media/custom_options/*
            !/pub/media/custom_options/.htaccess
            /pub/media/theme/*
            /pub/media/theme_customization/*
            !/pub/media/theme_customization/.htaccess
            /pub/media/wysiwyg/*
            !/pub/media/wysiwyg/.htaccess
            /pub/media/tmp/*
            !/pub/media/tmp/.htaccess
            /pub/media/captcha/*
            /pub/static/*
            !/pub/static/.htaccess

            /var/*
            !/var/.htaccess
            /vendor/*
            !/vendor/.htaccess
            /generated/*
            !/generated/.htaccess
            .DS_Store

            GITIGNORE
        );
    }
}
