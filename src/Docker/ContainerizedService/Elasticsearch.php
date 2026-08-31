<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Docker\Container;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Exception\ProcessFailedException;

class Elasticsearch extends AbstractService
{
    /**
     * Sleep for 1s and retry to connect in case Elasticsearch is still starting
     */
    private const CONNECTION_RETRIES = 60;

    /**
     * Number of possible checks in case the container is not `running`
     */
    private const STATE_CONNECTION_RETRIES = 10;

    /**
     * @param string $containerName
     * @return static
     */
    public function initialize(string $containerName): static
    {
        $self = parent::initialize($containerName);
        $self->testConnection();

        return $self;
    }

    /**
     * @return array
     * @throws \JsonException
     */
    public function getMeta(): array
    {
        // Some Elasticsearch containers have `curl`, some have `wget`...
        try {
            $process = $this->mustRun(
                'wget -q -O - http://localhost:9200', // no curl, but wget is installed
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );
        } catch (ProcessFailedException) {
            $process = $this->mustRun(
                'curl -XGET http://localhost:9200', // try curl if failed
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Major Elasticsearch version reported by the running container (e.g. 7 or 8). Used to pass the
     * correct `--search-engine=elasticsearchN` flag to `setup:install`: a single Magento version can
     * run either engine (2.4.7 ships both 7 and 8), and the two are not protocol-compatible, so the
     * flag must follow the container, not the Magento version.
     *
     * @return int
     * @throws \JsonException
     */
    public function getMajorVersion(): int
    {
        $meta = $this->getMeta();
        $version = $meta['version'] ?? [];
        $number = is_array($version) && isset($version['number']) && is_string($version['number'])
            ? $version['number']
            : '';

        if ($number === '') {
            throw new \RuntimeException(sprintf(
                'Unable to determine the Elasticsearch version for container "%s"',
                $this->getContainerName()
            ));
        }

        return (int) explode('.', $number)[0];
    }

    /**
     * @param int $connectionRetries
     * @return void
     */
    private function testConnection(int $connectionRetries = self::CONNECTION_RETRIES): void
    {
        $stateConnectionRetries = min($connectionRetries, self::STATE_CONNECTION_RETRIES);

        while ($connectionRetries--) {
            try {
                if ($this->getState() !== Container::CONTAINER_STATE_RUNNING) {
                    --$stateConnectionRetries;
                }

                if (!$stateConnectionRetries) {
                    throw new ContainerStateException(
                        '',
                        0,
                        null,
                        $this->getContainerName(),
                        Container::CONTAINER_STATE_RUNNING
                    );
                }

                $this->getMeta();

                return;
            } catch (ProcessFailedException) {
                if ($connectionRetries) {
                    sleep(1);

                    continue;
                }

                throw new \RuntimeException(
                    sprintf('Elasticsearch container "%s" is not responding', $this->getContainerName())
                );
            }
        }
    }
}
