<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use Symfony\Component\Process\Process;

class Docker
{
    /**
     * Handle `docker exec` from command to support passing complex arguments and options
     *
     * @param string $command
     * @param string $container
     * @param float|null $timeout
     * @return Process
     */
    public function run(string $command, string $container, ?float $timeout = 60): Process
    {
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
        $process->run();

        return $process;
    }

    /**
     * Handle `docker exec` from command to support passing complex arguments and options
     *
     * @param string $command
     * @param string $container
     * @param float|null $timeout
     * @return Process
     */
    public function mustRun(string $command, string $container, ?float $timeout = 60): Process
    {
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
        $process->mustRun();

        return $process;
    }
}
