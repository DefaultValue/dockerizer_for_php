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

class ActivemqArtemis extends AbstractService
{
    /**
     * Sleep for 1s and retry to connect in case ActiveMQ Artemis is still starting
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

                // Verify ActiveMQ Artemis broker is ready via native CLI check
                $this->mustRun(
                    '/opt/artemis/bin/artemis check node --up --url tcp://localhost:61616'
                        . ' --user ' . escapeshellarg($this->getEnvironmentVariable('ARTEMIS_USER'))
                        . ' --password ' . escapeshellarg($this->getEnvironmentVariable('ARTEMIS_PASSWORD')),
                    Shell::EXECUTION_TIMEOUT_SHORT,
                    false
                );

                return;
            } catch (ProcessFailedException) {
                if ($connectionRetries) {
                    sleep(1);

                    continue;
                }

                throw new \RuntimeException(
                    sprintf('ActiveMQ Artemis container "%s" is not responding', $this->getContainerName())
                );
            }
        }
    }
}
