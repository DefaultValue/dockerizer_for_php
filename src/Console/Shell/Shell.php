<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Shell;

use Symfony\Component\Process\Process;

class Shell
{
    private string $lastExecutedCommand = '';

    /**
     * @param array $command
     * @param string|null $cwd
     * @param array $env
     * @param string|null $input
     * @return string - command that was executed
     */
    public function exec(array $command, string $cwd = null, array $env = [], string $input = null): string
    {
        $process = new Process($command, $cwd, $env, $input);

        if ($input) {
            $process->setInput($input);
        }

        $process->run(null, $env);
        $this->lastExecutedCommand = $process->getCommandLine();

        if ($error = $process->getErrorOutput()) {
            return $error;
        }

        return '';
    }

    /**
     * @return string
     */
    public function getLastExecutedCommand(): string
    {
        return $this->lastExecutedCommand;
    }
}
