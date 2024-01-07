<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

class Events
{
    public const EVENT_TYPE_CONTAINER = 'container';
    public const EVENT_TYPE_NETWORK = 'network';

    private const EVENT_COMMAND = ['docker', 'events', '--format', '{{json .}}'];

    /** @var Process[] $processes */
    private array $processes = [];

    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(private \DefaultValue\Dockerizer\Shell\Shell $shell)
    {
    }

    /**
     * @param callable $callback
     * @param string[] $filters
     * @return void
     */
    public function addHandler(callable $callback, array $filters = []): void
    {
        /** @var string[] $preparedEventFilters */
        $preparedEventFilters = array_reduce(
            $filters,
            static fn(?array $carry, string $filter) => array_merge($carry ?? [], ['--filter'], [$filter])
        );

        // Start a background process
        $this->processes[] = $this->shell->start(
            array_merge(self::EVENT_COMMAND, $preparedEventFilters),
            null,
            [],
            null,
            Shell::EXECUTION_TIMEOUT_INFINITE,
            $callback
        );
    }

    /**
     * Watch for Docker events and exit on the background process exit or failure
     * @TODO: Extract this method to a separate class
     *
     * @return void
     */
    public function watch(): void
    {
        while (true) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    $errorMessage = sprintf(
                        'Process "%s" exited with code %d and output: %s',
                        $process->getCommandLine(),
                        $process->getExitCode(),
                        $process->getErrorOutput()
                    );

                    throw new \RuntimeException($errorMessage);
                }
            }

            usleep(10);
        }
    }
}
