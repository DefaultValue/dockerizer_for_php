<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

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
