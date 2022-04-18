<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Shell;

use Symfony\Component\Process\Process;

class Shell
{
    // private const EXECUTION_TIMEOUT_MEDIUM = 300; - unused for now

    public const EXECUTION_TIMEOUT_LONG = 3600;

    /**
     * @param string $command
     * @param string|null $cwd
     * @param array $env
     * @param string|null $input
     * @param float|null $timeout
     * @return Process
     */
    public function run(
        string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = 60
    ): Process {
        // Not yet sure we need to throw exception on error
        $process = Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->run(null, $env);

        return $process;
    }

    /**
     * @param string $command
     * @param string|null $cwd
     * @param array $env
     * @param string|null $input
     * @param float|null $timeout
     * @return Process
     */
    public function mustRun(
        string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = 60
    ): Process {
        // Not yet sure we need to throw exception on error
        $process = Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->mustRun(null, $env);

        return $process;
    }
}
