<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

class Docker
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(private \DefaultValue\Dockerizer\Shell\Shell $shell)
    {
    }

    /**
     * Handle `docker exec` from command to support passing complex arguments and options
     *
     * @param string $command
     * @param string $container
     * @param float|null $timeout
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function run(
        string $command,
        string $container,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT,
        bool $tty = true
    ): Process {
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
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
     * @param float|null $timeout
     * @param bool $tty - must be `false` to use `$process->getOutput()`
     * @return Process
     */
    public function mustRun(
        string $command,
        string $container,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT,
        bool $tty = true
    ): Process {
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
        $process->setTty($tty);
        $process->mustRun();

        return $process;
    }

    /**
     * @param string $containerName
     * @return string
     */
    public function getContainerIp(string $containerName): string
    {
        $process = $this->shell->mustRun(
            "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $containerName"
        );

        return trim($process->getOutput());
    }

    /**
     * @param string $file
     * @param string $mysqlContainerName
     * @param string $path
     * @return void
     */
    public function copyFileToContainer(string $file, string $mysqlContainerName, string $path = '/tmp'): void
    {
        $this->shell->mustRun("docker cp $file $mysqlContainerName:$path");
    }
}
