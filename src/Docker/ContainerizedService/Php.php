<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Shell\Shell;

class Php extends AbstractService
{
    /**
     * @return string
     */
    public function getPhpVersion(): string
    {
        $process = $this->mustRun('php -r \'echo phpversion();\'', Shell::EXECUTION_TIMEOUT_SHORT, false);

        return trim($process->getOutput());
    }
}
