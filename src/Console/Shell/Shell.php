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
     * @return Process
     */
    public function exec(array $command, string $cwd = null, array $env = [], string $input = null): Process
    {
        // Not yet sure we need to throw exception on error
        $process = new Process($command, $cwd, $env, $input);

        if ($input) {
            $process->setInput($input);
        }

        $process->run(null, $env);
        $this->lastExecutedCommand = $process->getCommandLine();

        return $process;
    }

    /**
     * @return string
     */
    public function getLastExecutedCommand(): string
    {
        return $this->lastExecutedCommand;
    }
}
