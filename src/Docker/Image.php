<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use Symfony\Component\Process\Process;

class Image
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(private \DefaultValue\Dockerizer\Shell\Shell $shell)
    {
    }

    /**
     * @param string $image
     * @param bool $force
     * @return void
     */
    public function pull(string $image, bool $force = false): void
    {
        if (!$force) {
            $process = $this->shell->run(['docker', 'images', '--format', '{{.Repository}}:{{.Tag}}']);
            $output = $process->getOutput();

            if (str_contains($output, $image)) {
                return;
            }
        }

        $this->shell->mustRun("docker pull $image");
    }
}
