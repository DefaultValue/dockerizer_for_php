<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

class Docker
{
    /**
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     */
    public function __construct(private \DefaultValue\Dockerizer\Console\Shell\Shell $shell)
    {
    }

    public function exec(array $command, string $container)
    {
        $dockerExecCommand = array_merge(
            ['docker', 'exec', $container],
            $command
        );

        return $this->shell->exec($dockerExecCommand);
    }
}
