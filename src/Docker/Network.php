<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use Symfony\Component\Process\Exception\ProcessFailedException;

class Network
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(private \DefaultValue\Dockerizer\Shell\Shell $shell)
    {
    }

    /**
     * @return string[]
     */
    public function ls(): array
    {
        $networks = trim($this->shell->run(
            ['docker', 'network', 'ls', '--format', '{{index .Name}}']
        )->getOutput());

        return explode(PHP_EOL, $networks);
    }

    /**
     * @param string $networkName
     * @return void
     * @throws ProcessFailedException
     */
    public function rm(string $networkName): void
    {
        $this->shell->mustRun(
            ['docker', 'network', 'rm', $networkName]
        );
    }

    /**
     * @param string $network
     * @param string $format
     * @return string
     */
    public function inspect(string $network, string $format): string
    {
        $process = $this->shell->mustRun(
            ['docker', 'network', 'inspect', '--format', $format, $network]
        );

        return trim($process->getOutput());
    }

    /**
     * @param string $network
     * @param string $format
     * @return array<string, mixed>
     * @throws \JsonException
     * @throws ProcessFailedException
     */
    public function inspectJsonWithDecode(string $network, string $format = ''): array
    {
        return json_decode($this->inspect($network, $format), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $networkName
     * @param string $containerName
     * @return void
     */
    public function connect(string $networkName, string $containerName): void
    {
        $this->shell->mustRun(
            ['docker', 'network', 'connect', $networkName, $containerName]
        );
    }

    /**
     * @param string $networkName
     * @param string $containerName
     * @return void
     */
    public function disconnect(string $networkName, string $containerName): void
    {
        $this->shell->mustRun(
            ['docker', 'network', 'disconnect', $networkName, $containerName]
        );
    }
}
