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
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function run(string $command, string $container, ?float $timeout = 60, bool $tty = true): Process
    {
        $process = Process::fromShellCommandline("docker exec -it $container $command", null, [], null, $timeout);
        // @TODO: do not use TTY mode in case command is run in the non-interactive mode (e.g., `-n`)?
        $process->setTty($tty);
        $process->run();

        return $process;
    }

    /**
     * Handle `docker exec` from command to support passing complex arguments and options
     *
     * @param string $command
     * @param string $container
     * @param float $timeout
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function mustRun(string $command, string $container, float $timeout = 60, bool $tty = true): Process
    {
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
        $process->setTty($tty);
        $process->mustRun();

        return $process;
    }
}
