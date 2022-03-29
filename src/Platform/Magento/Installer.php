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

        try {
            // Prepare installation directory
            if (!$this->filesystem->isEmptyDir($projectRoot)) {
                if ($force) {
                    $output->writeln('Cleaning up the project directory...');
                    $this->cleanUp($projectRoot);
                    $this->filesystem->getDirPath($projectRoot);
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

            $output->writeln('Generating composition files and running it...');
            $webRoot = $this->composition->getParameterValue('web_root', true);
            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDirPath($projectRoot . ltrim($webRoot, '\\/'));
            $modificationContext = $this->composition->dump($output, $projectRoot, $force);
            $dockerComposeDir = $modificationContext->getDockerComposeDir();

            $dockerCompose = $this->dockerCompose->setCwd($dockerComposeDir);
            $dockerCompose->up();

            // 3. Remove all Docker files so that the folder is empty
            $phpContainerName = $this->getPhpContainerName(
                $modificationContext->getCompositionYaml(),
                $dockerCompose
            );
//            $this->docker->exec(['sh', '-c \'rm -rf ./*\''], $phpContainerName);
//            $this->shell->dockerExec('sh -c "find ./ -type f -name \'.*\' -delete"', $mainDomain);
            $foo = false;
        } catch (InstallationDirectoryNotEmptyException|CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            $this->cleanUp($projectRoot);
            throw $e;
        }
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
}
