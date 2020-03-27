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
     * @param string $command - one or multiple commands, one command per line
     * @param bool $ignoreErrors
     * @param string $dir - the folder where to execute the command
     * @return $this
     * @throws \RuntimeException
     */
    public function passthru(string $command, bool $ignoreErrors = false, string $dir = ''): self
    {
        $exitCode = 0;

        foreach ($this->prepareCommands($command, $dir) as $preparedCommand) {
            passthru($preparedCommand, $exitCode);

            if ($exitCode && !$ignoreErrors) {
                throw new \RuntimeException(
                    'Execution failed. External command returned non-zero exit code: ' . $preparedCommand
                );
            }
        }

        return $this;
    }

    /**
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command - one or multiple commands, one command per line
     * @param bool $ignoreErrors
     * @throws \RuntimeException
     */
    public function sudoPassthru(string $command, bool $ignoreErrors = false): void
    {
        $this->passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command", $ignoreErrors);
    }

    /**
     * Execute command and return output. Throw exception in case of execution error
     * @param string $command - one or multiple commands, one command per line
     * @param string $dir - the folder where to execute the command
     * @return array
     * @throws \RuntimeException
     */
    public function exec(string $command, $dir = ''): array
    {
        $fullExecutionResult = [];

        foreach ($this->prepareCommands($command, $dir) as $preparedCommand) {
            exec($preparedCommand, $result, $exitCode);

            if ($exitCode) {
                throw new \RuntimeException(
                    'Execution failed. External command returned non-zero exit code: ' . $preparedCommand
                    . 'Error message: ' . end($result)
                );
            }

            $fullExecutionResult[] = $result;
        }

        $fullExecutionResult = array_filter(array_merge([], ...$fullExecutionResult));

        if (empty($fullExecutionResult)) {
            throw new \RuntimeException("Command didn't return output: $command");
        }

        return $fullExecutionResult;
    }

    /**
     * Convert multiple commands to array of individual commands with 'cd' instruction
     * because we must exec each command separately in order to get exit codes for each individual command
     *
     * @param string $command
     * @param string $dir - the folder where to execute the command
     * @return array
     */
    private function prepareCommands(string $command, string $dir = ''): array
    {
        $commands = array_filter(explode("\n", str_replace("\\\n", '', $command)));
        $preparedCommands = [];

        if ($dir && !is_dir($dir)) {
            throw new FilesystemException("Directory to execute command in does not exist: $dir");
        }

        foreach ($commands as $individualCommand) {
            $preparedCommand = trim($individualCommand);

            if ($dir) {
                $preparedCommand = "cd $dir && $preparedCommand";
            }

            $preparedCommands[] = $preparedCommand . ' 2>&1';
        }

        return $preparedCommands;
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
        $this->passthru($command);

        return $this;
    }
}
