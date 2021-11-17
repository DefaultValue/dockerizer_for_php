<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

class Composition
{
    private array $services = [];

    public function __construct(
        \DefaultValue\Dockerizer\Filesystem\NewFileCollection $collection
    ) {
    }

    /**
     * @param Service $service
     * @return $this
     */
    public function addService(Service $service): self
    {
        //  @TODO: validate environment variables used by the service
        $this->services[] = $service;

        return $this;
    }

    public function getFiles()
    {

    }

    public function dump()
    {

    }
}
