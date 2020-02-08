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
     * @param string $command
     * @param bool $ignoreErrors
     * @return $this
     */
    public function passthru(string $command, bool $ignoreErrors = false): self
    {
        $exitCode = 0;

        passthru($command, $exitCode);

        if ($exitCode && !$ignoreErrors) {
            throw new \RuntimeException('Execution failed. External command returned non-zero exit code.');
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

    public function shellExec(string $command)
    {
        // throw exception on error
        return
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
