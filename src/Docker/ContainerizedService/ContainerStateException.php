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

class ContainerStateException extends \RuntimeException
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param string $containerName
     * @param string $expectedState
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $containerName = '',
        string $expectedState = ''
    ) {
        if ($containerName) {
            $message .= "\nDocker container state error for container: $containerName";
        }

        if ($expectedState) {
            $message .= "\nExpected container state is: $containerName.";
        }

        parent::__construct(trim($message), $code, $previous);
    }
}
