<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

class AbstractService
{
    public const CONTAINER_STATE_CREATED = 'created';
    public const CONTAINER_STATE_RUNNING = 'running';
    public const CONTAINER_STATE_RESTARTING = 'restarting';
    public const CONTAINER_STATE_EXITED = 'exited';
    public const CONTAINER_STATE_PAUSED = 'paused';
    public const CONTAINER_STATE_DEAD = 'dead';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param string $containerName
     */
    final public function __construct(
        protected \DefaultValue\Dockerizer\Docker\Docker $docker,
        private string $containerName = ''
    ) {
    }

    /**
     * Set service name to work with, validate it
     *
     * @param string $containerName
     * @return static
     */
    public function initialize(string $containerName): static
    {
        if (!$containerName) {
            throw new \InvalidArgumentException('Container name must not be empty!');
        }

        $self = new static($this->docker, $containerName);

        if ($self->getState() !== self::CONTAINER_STATE_RUNNING) {
            throw new \RuntimeException("Container does not exist or is not running!");
        }

        return $self;
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

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->docker->containerInspectWithFormat($this->getContainerName(), '.State.Status');
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
     * @param string $environmentVariable
     * @return string
     */
    public function getEnvironmentVariable(string $environmentVariable): string
    {
        return trim($this->run("printenv $environmentVariable", Shell::EXECUTION_TIMEOUT_SHORT, false)->getOutput());
    }

    /**
     * @param string $label
     * @return string
     */
    public function getLabel(string $label): string
    {
        return $this->docker->containerInspectWithFormat(
            $this->getContainerName(),
            sprintf('index .Config.Labels "%s"', $label)
        );
    }
}
