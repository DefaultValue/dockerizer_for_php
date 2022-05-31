<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Container;

class Php extends AbstractService
{
    /**
     * @return string
     */
    public function getPhpVersion(): string
    {
        $process = $this->docker->mustRun('php -r \'echo phpversion();\'', $this->getContainerName(), 60, false);

        return trim($process->getOutput());
    }
}
