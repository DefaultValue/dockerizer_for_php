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
