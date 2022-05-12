<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use DefaultValue\Dockerizer\Console\Shell\Shell;
use DefaultValue\Dockerizer\Docker\Compose\CompositionFilesNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class Compose
{
    // For production-only run
    private const DOCKER_COMPOSE_NAME_PATTERNS = [
        'docker-compose.yml',
        'docker-compose.yaml'
    ];

    // To run with dev tools or any other files (docker-compose-override.yml)
    private const DOCKER_COMPOSE_EXTENDED_NAME_PATTERNS = [
        'docker-compose*.yml',
        'docker-compose*.yaml'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     * @param string $cwd
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell,
        private string $cwd = ''
    ) {
    }

    /**
     * @return string
     */
    public function getCwd(): string
    {
        return $this->cwd;
    }

    /**
     * Set current working directory for the class instance
     *
     * @param string $cwd
     * @return Compose
     */
    public function setCwd(string $cwd): Compose
    {
        if (!$cwd) {
            throw new \InvalidArgumentException('Working directory must not be empty!');
        }

        return new self($this->shell, $cwd);
    }

    /**
     * @param bool $forceRecreate
     * @param bool $production
     * @return void
     */
    public function up(bool $forceRecreate = true, bool $production = false): void
    {
        // @TODO: can add option to run this in production mode
        $command = $this->getDockerComposeCommand($production) . ' up -d';

        if ($forceRecreate) {
            $command .= ' --force-recreate';
        }

        $process = $this->shell->run(
            $command,
            $this->getCwd(),
            [],
            null,
            Shell::EXECUTION_TIMEOUT_LONG // in case of downloading Docker images
        );

        if ($error = $process->getErrorOutput()) {
            // Creating network, volumes and containers is passed to the error stream for some reason
            /*
             Creating network "test-apachelocal-dev_default" with the default driver
             Creating volume "test-apachelocal-dev_mysql_dev_data" with default driver
             Creating volume "test-apachelocal-dev_elasticsearch_dev_data" with default driver
             Creating test-apachelocal-dev_mailhog_1 ...
             Creating test-apachelocal-dev_redis_1   ...
             Creating test-apachelocal-dev_mysql_1   ...
             Creating test-apachelocal-dev_elasticsearch_1 ...
             Creating test-apache.local-dev          ...
             [4A[2K
             Creating test-apachelocal-dev_redis_1         ... [32mdone[0m
             [4B[3A[2K
             Creating test-apachelocal-dev_mysql_1         ... [32mdone[0m
             [3BCreating test-apachelocal-dev_phpmyadmin_1    ...
             [3A[2K
             Creating test-apache.local-dev                ... [32mdone[0m
             [3B[2A[2K
             Creating test-apachelocal-dev_elasticsearch_1 ... [32mdone[0m
             [2B[6A[2K
             Creating test-apachelocal-dev_mailhog_1       ... [32mdone[0m
             [6B[1A[2K
             Creating test-apachelocal-dev_phpmyadmin_1    ... [32mdone[0m
             [1B
             */
            foreach (array_map('trim', explode("\n", trim($error))) as $errorLine) {
                if (
                    str_starts_with($errorLine, 'Pulling ')
                    || str_starts_with($errorLine, 'Creating network "')
                    || str_starts_with($errorLine, 'Creating volume "')
                    || (str_starts_with($errorLine, 'Creating ') && str_ends_with($errorLine, '...'))
                    || (
                        str_contains($errorLine, 'Creating ')
                        && str_contains($errorLine, 'done')
                        && !str_contains($errorLine, 'fail')
                    )
                ) {
                    continue;
                }

                throw new \RuntimeException($error);
            }
        }
    }

    /**
     * @param bool $volumes
     * @return void
     */
    public function down(bool $volumes = true, /* bool $removeOrphans = true */): void
    {
        $command = $this->getDockerComposeCommand();
        $command .= ' down --remove-orphans';

        if ($volumes) {
            $command .= ' --volumes';
        }

        if ($error = $this->shell->run($command, $this->getCwd())->getErrorOutput()) {
            // Inability to remove network or volume is not an issue, because the composition may not be running
            /*
             Stopping test-apachelocal-dev_phpmyadmin_1    ...
             Stopping test-apache.local-dev                ...
             Stopping test-apachelocal-dev_mysql_1         ...
             Stopping test-apachelocal-dev_redis_1         ...
             Stopping test-apachelocal-dev_elasticsearch_1 ...
             Stopping test-apachelocal-dev_mailhog_1       ...
             [1A[2K
             Stopping test-apachelocal-dev_mailhog_1       ... [32mdone[0m
             [1B[5A[2K
             Stopping test-apache.local-dev                ... [32mdone[0m
             [5B[3A[2K
             Stopping test-apachelocal-dev_redis_1         ... [32mdone[0m
             [3B[2A[2K
             Stopping test-apachelocal-dev_elasticsearch_1 ... [32mdone[0m
             [2B[6A[2K
             Stopping test-apachelocal-dev_phpmyadmin_1    ... [32mdone[0m
             [6B[4A[2K
             Stopping test-apachelocal-dev_mysql_1         ... [32mdone[0m
             [4BRemoving test-apachelocal-dev_phpmyadmin_1    ...
             Removing test-apache.local-dev                ...
             Removing test-apachelocal-dev_mysql_1         ...
             Removing test-apachelocal-dev_redis_1         ...
             Removing test-apachelocal-dev_elasticsearch_1 ...
             Removing test-apachelocal-dev_mailhog_1       ...
             [4A[2K
             Removing test-apachelocal-dev_mysql_1         ... [32mdone[0m
             [4B[1A[2K
             Removing test-apachelocal-dev_mailhog_1       ... [32mdone[0m
             [1B[5A[2K
             Removing test-apache.local-dev                ... [32mdone[0m
             [5B[3A[2K
             Removing test-apachelocal-dev_redis_1         ... [32mdone[0m
             [3B[2A[2K
             Removing test-apachelocal-dev_elasticsearch_1 ... [32mdone[0m
             [2B[6A[2K
             Removing test-apachelocal-dev_phpmyadmin_1    ... [32mdone[0m
             [6BRemoving network test-apachelocal-dev_default
             Removing volume test-apachelocal-dev_mysql_dev_data
             Removing volume test-apachelocal-dev_elasticsearch_dev_data
             */
            foreach (array_map('trim', explode("\n", trim($error))) as $errorLine) {
//                str_starts_with($errorLine, 'Creating network "')
//                || str_starts_with($errorLine, 'Creating volume "')
//                || (str_starts_with($errorLine, 'Creating ') && str_ends_with($errorLine, '...'))
//                || (
//                    str_contains($errorLine, 'Creating ')
//                    && str_contains($errorLine, 'done')
//                    && !str_contains($errorLine, 'fail')
//                )

                if (
                    str_starts_with($errorLine, 'Removing network ')
                    || str_starts_with($errorLine, 'Removing volume ')
                    || (str_starts_with($errorLine, 'Stopping ') && str_ends_with($errorLine, '...'))
                    || (str_starts_with($errorLine, 'Removing ') && str_ends_with($errorLine, '...'))
                    || (str_starts_with($errorLine, 'Network ') && str_ends_with($errorLine, ' not found.'))
                    || (str_starts_with($errorLine, 'Volume ') && str_ends_with($errorLine, ' not found.'))
                    || (
                        (str_contains($errorLine, 'Stopping ') || str_contains($errorLine, 'Removing '))
                        && str_contains($errorLine, 'done')
                        && !str_contains($errorLine, 'fail')
                    )
                ) {
                    continue;
                }

                throw new \RuntimeException($error);
            }
        }
    }

    /**
     * @return array
     */
    public function ps(): array
    {
        // Based on https://github.com/docker/compose/issues/1513#issuecomment-109173246
        $command = sprintf(
            'docker inspect -f \'{{if .State.Running}}' .
            '{{index .Config.Labels "com.docker.compose.service"}}{{.Name}}' .
            '{{end}}\' $(%s)',
            $this->getDockerComposeCommand() . ' ps -q'
        );
        $process = $this->shell->mustRun($command, $this->getCwd());
        $output = explode("\n", trim($process->getOutput()));
        $runningContainers = [];

        // @TODO: implement this by getting data from `docker compose ps` instead of `docker-compose`
        foreach ($output as $containerData) {
            [$serviceName, $containerName] = explode('/', $containerData);
            $runningContainers[$serviceName] = $containerName;
        }

        return $runningContainers;
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    public function hasService(string $serviceName): bool
    {
        // @TODO: ensure the service is running?
        $compositionYaml = $this->getCompositionYaml();

        return isset($compositionYaml['services'][$serviceName]);
    }

    /**
     * Not yet tested with special chars or some tricky encodings in the domain name
     *
     * @param string $serviceName
     * @return string
     * @throws \Exception
     */
    public function getServiceContainerName(string $serviceName): string
    {
        // Get container name by service name from the running containers otherwise
        $runningContainers = $this->ps();

        if (isset($runningContainers[$serviceName])) {
            return $runningContainers[$serviceName];
        }

        throw new \RuntimeException("Can't find a container name for the service: $serviceName");
    }

    /**
     * Get YAML from the materialized composition files
     *
     * @return array
     */
    private function getCompositionYaml(): array
    {
        $compositionYaml = [];

        foreach ($this->getDockerComposeFiles() as $dockerComposeFile) {
            $compositionYaml[] = Yaml::parseFile($dockerComposeFile->getRealPath());
        }

        return array_merge_recursive(...$compositionYaml);
    }

    /**
     * @param bool $production
     * @return string
     */
    private function getDockerComposeCommand(bool $production = false): string
    {
        $command = 'docker-compose';

        foreach ($this->getDockerComposeFiles($production) as $dockerComposeFile) {
            $command .= ' -f ' . $dockerComposeFile->getFilename();
        }

        return $command;
    }

    /**
     * @return Finder
     */
    private function getDockerComposeFiles(bool $production = false): Finder
    {
        if (!$this->getCwd()) {
            throw new \RuntimeException('Set the directory containing docker-compose files');
        }

        $files = Finder::create()->in($this->getCwd())->files()->name(
            $production ? self::DOCKER_COMPOSE_NAME_PATTERNS : self::DOCKER_COMPOSE_EXTENDED_NAME_PATTERNS
        );

        if (!$files->hasResults()) {
            throw new CompositionFilesNotFoundException(
                'No docker-compose file(s) found in the directory ' . $this->getCwd()
            );
        }

        return $files;
    }
}
