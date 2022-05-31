<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Container;

class AbstractService
{
    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     * @param string $containerName
     */
    public function __construct(
        protected \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell,
        private string $containerName = ''
    ) {
    }

    /**
     * Set service name to work with, validate it
     *
     * @param string $containerName
     * @return $this
     */
    public function setContainerName(string $containerName): static
    {
        if (!$containerName) {
            throw new \InvalidArgumentException('Container name must not be empty!');
        }

        $process = $this->shell->mustRun("docker container inspect -f '{{.State.Running}}' $containerName");

        if (trim($process->getOutput()) !== 'true') {
            throw new \RuntimeException("Container does not exist or is not running!");
        }

        return new static($this->docker, $this->shell, $containerName);
    }

    /**
     * @return string
     */
    public function getContainerName(): string
    {
        if (!$this->containerName) {
            throw new \LogicException('Container name must not be empty!');
        }

        return $this->containerName;
    }
}
