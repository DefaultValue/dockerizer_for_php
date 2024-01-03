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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Container
{
    public const CONTAINER_STATE_CREATED = 'created';
    public const CONTAINER_STATE_RUNNING = 'running';
    public const CONTAINER_STATE_RESTARTING = 'restarting';
    public const CONTAINER_STATE_EXITED = 'exited';
    public const CONTAINER_STATE_PAUSED = 'paused';
    public const CONTAINER_STATE_DEAD = 'dead';

    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
    ) {
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
        // @TODO: replace with `new Process()`
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
        // @TODO: replace with `new Process()`
        $process = Process::fromShellCommandline("docker exec $container $command", null, [], null, $timeout);
        $process->setTty($tty);
        $process->mustRun();

        return $process;
    }

    /**
     * Get running Docker containers
     *
     * @return array<string, array<string, string>>
     * @throws \JsonException
     */
    public function ps(): array
    {
        $output = trim($this->shell->mustRun(['docker', 'ps', '--format', '{{json .}}'])->getOutput());

        return array_map(
            static fn (string $item) => json_decode($item, true, 512, JSON_THROW_ON_ERROR),
            explode(PHP_EOL, $output)
        );
    }

    /**
     * @param string $containerName
     * @return string
     */
    public function getIp(string $containerName): string
    {
        return $this->inspect($containerName, '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}');
    }

    /**
     * @param string $container
     * @param string $format
     * @return string
     */
    public function inspect(string $container, string $format = ''): string
    {
        $process = $this->shell->mustRun(array_merge(
            ['docker', 'container', 'inspect', $container],
            $format ? ['--format', $format] : []
        ));

        return trim($process->getOutput());
    }

    /**
     * @param string $container
     * @param string $format
     * @return array<string, mixed>
     * @throws \JsonException
     * @throws ProcessFailedException
     */
    public function inspectJsonWithDecode(string $container, string $format = ''): array
    {
        return json_decode($this->inspect($container, $format), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $path
     * @param string $container
     * @return bool
     */
    public function isFile(string $path, string $container): bool
    {
        return $this->run("test -f $path", $container)->isSuccessful();
    }

    /**
     * @param string $path
     * @param string $container
     * @return string
     */
    public function fileGetContents(string $path, string $container): string
    {
        if (!$this->isFile($path, $container)) {
            throw new \RuntimeException("File $path does not exist!");
        }

        return trim($this->run("cat $path", $container, null, false)->getOutput());
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $container
     * @return void
     */
    public function filePutContents(string $path, string $content, string $container): void
    {
        $fileName = basename($path);
        $tempFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'dockerizer_', $fileName);
        $this->filesystem->filePutContents($tempFile, $content);

        try {
            $this->copyFileToContainer($tempFile, $container, dirname($path));
        } finally {
            $this->filesystem->remove($tempFile);
        }
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
