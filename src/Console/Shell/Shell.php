<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Shell;

use Symfony\Component\Process\Process;

class Shell
{
    /**
     * @param array $command
     * @param string $cwd
     * @param array $env
     * @return string
     */
    public function exec(array $command, string $cwd, array $env = []): string
    {
        $process = new Process($command, $cwd, $env);
        $process->run(null, $env);

        if ($error = $process->getErrorOutput()) {
            return $error;
        }

        return '';
    }
}
