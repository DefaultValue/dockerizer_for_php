<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

class MountedFile
{
    /**
     * Get patch relative to the current docker-compose.yaml file in the project
     *
     * @return string
     */
    public function getRelativePath(): string
    {
        return '';
    }

    public function getProcessedContents(): string
    {
        throw new \Exception('To be implemented');

        return $this;
    }

    public function setProcessedContents(): self
    {
        throw new \Exception('To be implemented');
    }
}
