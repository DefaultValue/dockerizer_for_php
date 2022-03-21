<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install Magento
 *
 * Composition template and services myst be configured in the respective command (or web app if we ever get it)
 */
class Installer
{
    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
    ) {
    }

    /**
     * @param OutputInterface $output
     * @param array $domains
     * @param bool $force
     * @return void
     */
    public function install(OutputInterface $output, string $magentoVersion, array $domains, bool $force)
    {
        try {
            $mainDomain = $domains[0];
            $projectRoot = $this->getProjectRoot($mainDomain);

            // Prepare installation directory
            if (!$this->filesystem->isEmptyDir($projectRoot)) {
                if ($force) {
                    $this->cleanUp($mainDomain);
                    $this->filesystem->getDirPath($mainDomain, true);
                } else {
                    // Unset variable so that project files are not removed in this particular case
                    unset($mainDomain);
                    throw new \InvalidArgumentException(<<<EOF
                    Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
                    Stop all containers (if any), remove the folder and re-run setup.
                    You can also use '-f' option to force install Magento with this domain.
                    EOF);
                }
            }

            $webRoot = $this->composition->getParameterValue('web_root', true);
            $foo = false;

        } catch (\Exception $e) {

        }



        // $this->composition->dump($output, $projectRoot, $force);


    }

    /**
     * @param string $dir
     * @return string
     */
    public function getProjectRoot(string $dir): string
    {
        return $this->filesystem->getDirPath($dir, true);
    }

    private function cleanUp(string $mainDomain = ''): void
    {
        if (!$mainDomain) {
            return;
        }

        try {
            $projectRoot = $this->filesystem->getDirPath($mainDomain, false, true);

            if ($this->filesystem->isWritableFile($projectRoot . 'docker-compose.yml')) {
                $this->shell->passthru('docker-compose down --remove-orphans 2>/dev/null', true, $projectRoot);
            } else {
                // Handle the case when we fail while installing Magento and do not have the docker-compose.yml
                $mainDockerContainer = str_replace('.', '', $mainDomain);

                try {
                    // For some reasons this command may return error if no containers were found
                    $dockerContainers = $this->shell->exec("docker ps | grep $mainDockerContainer", '', true);
                    $dockerContainers = array_map(static function ($value) {
                        return array_values(array_filter(explode(' ', $value)))[1];
                    }, $dockerContainers);
                } catch (\Exception $e) {
                    $dockerContainers = [];
                }

                foreach ($dockerContainers as $dockerContainer) {
                    $this->shell->passthru(
                        "docker stop $dockerContainer && docker rm $dockerContainer",
                        true,
                        $projectRoot
                    );
                }
            }

            $currentUser = get_current_user();
            $userGroup = filegroup($this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE));
            $this->shell->sudoPassthru("chown -R $currentUser:$userGroup $projectRoot");
            $this->shell->sudoPassthru("rm -rf $projectRoot");
        } catch (\Exception $e) {
        }

        if ($mysqlContainer) {
            $this->database->dropDatabase($mainDomain);
        }
    }
}
