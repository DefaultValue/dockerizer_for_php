<?php

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
