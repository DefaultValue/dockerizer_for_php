<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

class Container
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
        return $this->containerInspectWithFormat(
            $containerName,
            '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
        );
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

    /**
     * @param string $containerName
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public function containerInspect(string $containerName): array
    {
        $process = $this->shell->mustRun(sprintf('docker container inspect %s', escapeshellarg($containerName)));

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR)[0];
    }

    /**
     * @param string $containerName
     * @param string $format
     * @return string
     */
    public function containerInspectWithFormat(string $containerName, string $format): string
    {
        $process = $this->shell->mustRun(
            sprintf('docker container inspect -f \'%s\' %s', $format, escapeshellarg($containerName))
        );

        return trim($process->getOutput());
    }
}
