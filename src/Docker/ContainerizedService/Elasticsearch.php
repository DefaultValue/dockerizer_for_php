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
use Symfony\Component\Process\Exception\ProcessFailedException;

class Elasticsearch extends AbstractService
{
    /**
     * @return array
     * @throws \JsonException
     */
    public function getMeta(): array
    {
        // Some Elasticsearch containers have `curl`, some have `wget`...
        try {
            $process = $this->mustRun(
                'wget -q -O - http://localhost:9200', // no curl, but wget is installed
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );
        } catch (ProcessFailedException) {
            $process = $this->mustRun(
                'curl -XGET http://localhost:9200', // try curl if failed
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }
}
