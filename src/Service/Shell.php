<?php
declare(strict_types=1);

namespace App\Service;

class Shell
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * Shell constructor.
     * @param \App\Config\Env $env
     */
    public function __construct(\App\Config\Env $env)
    {
        $this->env = $env;
    }

    /**
     * Execute command and display output. Throw exception in case of execution error
     * @param string $command
     * @param bool $ignoreErrors
     * @return $this
     */
    public function passthru(string $command, bool $ignoreErrors = false): self
    {
        $exitCode = 0;

        passthru($command, $exitCode);

        if ($exitCode && !$ignoreErrors) {
            throw new \RuntimeException('Execution failed. External command returned non-zero exit code: ' . $command);
        }

        return $this;
    }

    /**
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command
     * @param bool $ignoreErrors
     */
    public function sudoPassthru(string $command, bool $ignoreErrors = false): void
    {
        $this->passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command", $ignoreErrors);
    }

    /**
     * Execute command and return output. Throw exception in case of execution error
     * @param string $command
     * @return array
     */
    public function exec(string $command): array
    {
        exec($command, $result, $exitCode);

        if ($exitCode || !$result) {
            throw new \RuntimeException('Execution failed. External command returned non-zero exit code: ' . $command);
        }

        return $result;
    }

    /**
     * @param string $command
     * @param string $container
     * @return $this
     * @throws \RuntimeException
     */
    public function dockerExec(string $command, string $container): self
    {
        if (!shell_exec("docker ps | grep $container | grep 'Up '")) {
            throw new \RuntimeException("Can't continue because the container $container is not up and running.");
        }

        $command = "docker exec -it $container " . str_replace(["\r", "\n"], '', $command);

        echo "$command\n\n";
        $this->passthru($command);

        return $this;
    }
}
