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

class Shell
{
    public const EXECUTION_TIMEOUT_SHORT = 60;

    public const EXECUTION_TIMEOUT_MEDIUM = 600;

    public const EXECUTION_TIMEOUT_LONG = 3600;

    // 6 hours for extra long operations like importing a huge DB dump
    public const EXECUTION_TIMEOUT_VERY_LONG = 21600;

    /**
     * @param string $command
     * @param string|null $cwd
     * @param string[] $env
     * @param string|null $input
     * @param float|null $timeout
     * @return Process
     */
    public function run(
        string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = self::EXECUTION_TIMEOUT_SHORT
    ): Process {
        // Not yet sure we need to throw exception on error
        $process = Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->run(null, $env);

        return $process;
    }

    /**
     * @param string $command
     * @param string|null $cwd
     * @param string[] $env
     * @param string|null $input
     * @param float|null $timeout
     * @return Process
     */
    public function mustRun(
        string $command,
        string $cwd = null,
        array $env = [],
        string $input = null,
        ?float $timeout = self::EXECUTION_TIMEOUT_SHORT
    ): Process {
        // Not yet sure we need to throw exception on error
        $process = Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $process->mustRun(null, $env);

        return $process;
    }
}
