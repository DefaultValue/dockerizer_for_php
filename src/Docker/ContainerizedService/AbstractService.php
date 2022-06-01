<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Console\Shell\Shell;
use Symfony\Component\Process\Process;

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
    public function initialize(string $containerName): static
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
     * @param string $command
     * @param float|null $timeout
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function run(
        string $command,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT,
        bool $tty = true
    ): Process {
        return $this->docker->run($command, $this->getContainerName(), $timeout, $tty);
    }

    /**
     * @param string $command
     * @param float|null $timeout
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function mustRun(
        string $command,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT,
        bool $tty = true
    ): Process {
        return $this->docker->mustRun($command, $this->getContainerName(), $timeout, $tty);
    }

    /**
     * @return string
     */
    protected function getContainerName(): string
    {
        if (!$this->containerName) {
            throw new \LogicException('Container name must not be empty!');
        }

        return $this->containerName;
    }
}
