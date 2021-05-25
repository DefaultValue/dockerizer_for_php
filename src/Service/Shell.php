<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Command\Command;

class Shell
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    private $logFile;

    /**
     * Shell constructor.
     * @param \App\Config\Env $env
     */
    public function __construct(\App\Config\Env $env)
    {
        $this->env = $env;
        $this->logFile = $this->getLogFile();
    }

    /**
     * Execute command and display output. Throw exception in case of execution error
     * @param string $command - one or multiple commands, one command per line
     * @param ?bool $ignoreErrors
     * @param string $dir - the folder where to execute the command
     * @return $this
     * @throws \RuntimeException
     */
    public function passthru(string $command, ?bool $ignoreErrors = false, string $dir = ''): self
    {
        $exitCode = Command::SUCCESS;

        foreach ($this->prepareCommands($command, $dir) as $preparedCommand) {
            $this->log($preparedCommand);
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
     * @param bool $allowEmptyOutput - allow commands that are executed in the silent mode
     *                                 or are silenced manually due to the massive output
     * @return array
     * @throws \RuntimeException
     */
    public function exec(string $command, string $dir = '', bool $allowEmptyOutput = false): array
    {
        $fullExecutionResult = [];

        foreach ($this->prepareCommands($command, $dir) as $preparedCommand) {
            $this->log($preparedCommand);
            exec($preparedCommand, $result, $exitCode);

            if ($exitCode) {
                throw new \RuntimeException(
                    'Execution failed. External command returned non-zero exit code: ' . $preparedCommand . "\n" .
                    'Error message: ' . end($result)
                );
            }

            $fullExecutionResult[] = $result;
        }

        $fullExecutionResult = array_filter(array_merge([], ...$fullExecutionResult));

        if (!$allowEmptyOutput && empty($fullExecutionResult)) {
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

            if (strpos($preparedCommand, '/dev/null') === false && strpos($preparedCommand, 'docker ') !== 0) {
                $preparedCommand .= ' 2>&1';
            }

            $preparedCommands[] = $preparedCommand;
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

        $command = "docker exec $container $command";
        $this->passthru($command);

        return $this;
    }

    /**
     * @TODO: use logger instead
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        file_put_contents(
            $this->logFile,
            "{$this->getDateTime()}: $message\n",
            FILE_APPEND
        );
    }

    /**
     * @return string
     */
    private function getDateTime(): string
    {
        return date('Y-m-d_H:i:s');
    }

    /**
     * @return string
     */
    private function getLogFile(): string
    {
        return $this->env->getProjectsRootDir()
            . 'dockerizer_for_php' . DIRECTORY_SEPARATOR
            . 'var' . DIRECTORY_SEPARATOR
            . 'log' . DIRECTORY_SEPARATOR
            . uniqid('shell_' . $this->getDateTime() . '_', false) . '.log';
    }
}
