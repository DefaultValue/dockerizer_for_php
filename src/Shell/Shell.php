<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Shell;

use Symfony\Component\Process\Process;

// @TODO: get command > return and log it > run it. We should be able to return commands and log them.
class Shell
{
    public const EXECUTION_TIMEOUT_SHORT = 60;
    public const EXECUTION_TIMEOUT_MEDIUM = 600;
    public const EXECUTION_TIMEOUT_LONG = 3600;
    // 6 hours for extra long operations like importing a huge DB dump
    public const EXECUTION_TIMEOUT_VERY_LONG = 21600;
    public const EXECUTION_TIMEOUT_INFINITE = 0;

    /**
     * @param string[] $command
     * @param string|null $cwd
     * @param string[] $env
     * @param string|null $input
     * @param float|null $timeout
     * @param callable|null $callback
     * @return Process
     */
    public function start(
        array $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = self::EXECUTION_TIMEOUT_SHORT,
        ?callable $callback = null
    ): Process {
        // Not yet sure we need to throw exception on error
        $process = new Process($command, $cwd, $env, $input, $timeout);
        $process->start($callback, $env);

        return $process;
    }

    /**
     * @param string[]|string $command
     * @param string|null $cwd
     * @param string[] $env
     * @param string|null $input
     * @param float|null $timeout
     * @param callable|null $callback
     * @return Process
     */
    public function run(
        array|string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = self::EXECUTION_TIMEOUT_SHORT,
        ?callable $callback = null
    ): Process {
        // Not yet sure we need to throw exception on error
        // @TODO: replace with `new Process()`
        $process = is_array($command)
            ? new Process($command, $cwd, $env, $input, $timeout)
            : Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->run($callback, $env);

        return $process;
    }

    /**
     * @param string[]|string $command
     * @param string|null $cwd
     * @param string[] $env
     * @param string|null $input
     * @param float|null $timeout
     * @param callable|null $callback
     * @return Process
     */
    public function mustRun(
        array|string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = self::EXECUTION_TIMEOUT_SHORT,
        ?callable $callback = null
    ): Process {
        // Not yet sure we need to throw exception on error
        // @TODO: replace with `new Process()`
        $process = is_array($command)
            ? new Process($command, $cwd, $env, $input, $timeout)
            : Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->mustRun($callback, $env);

        return $process;
    }
}
