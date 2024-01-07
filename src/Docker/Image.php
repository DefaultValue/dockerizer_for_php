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

use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
     * @param bool $skipIfLocalImageExists
     * @param bool $mustRun
     * @return void
     */
    public function pull(string $image, bool $skipIfLocalImageExists = true, bool $mustRun = true): void
    {
        if ($skipIfLocalImageExists) {
            $process = $this->shell->run(['docker', 'images', '--format', '{{.Repository}}:{{.Tag}}']);
            $output = $process->getOutput();

            if (str_contains($output, $image)) {
                return;
            }
        }

        $process = $this->shell->run("docker pull $image", null, [], null, Shell::EXECUTION_TIMEOUT_LONG);

        if (
            PHP_OS_FAMILY === 'Darwin'
            && !$process->isSuccessful()
            && str_contains($process->getErrorOutput(), 'no matching manifest for')
        ) {
            $dockerArch = trim($this->shell->run('docker version --format {{.Server.Arch}}')->getOutput());

            if ($dockerArch === 'arm64') {
                $process = $this->shell->run(
                    "docker pull --platform linux/amd64 $image",
                    null,
                    [],
                    null,
                    Shell::EXECUTION_TIMEOUT_LONG
                );
            }
        }

        if ($mustRun && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
