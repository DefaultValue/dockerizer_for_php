<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

class Composition
{
    /**
     * @var Service[]
     */
    private array $additionalServices = [];

    private Service $runner;

    /**
     * @param Service $service
     * @param bool $isRunner
     * @return $this
     */
    public function addService(Service $service, bool $isRunner = false): self
    {
        //  @TODO: validate environment variables used by the service
        // $service->selfValidate();
        $serviceCode = $service->getCode();

        if (isset($this->additionalServices[$serviceCode])) {
            throw new \RuntimeException("Service $serviceCode already exists in the composition");
        }

        if ($isRunner) {
            if (isset($this->runner)) {
                throw new \RuntimeException(sprintf(
                    'Composition runner is already set. Old runner: %s. New runner: %s',
                    $this->runner->getCode(),
                    $serviceCode
                ));
            }

            $this->runner = $service;
        } else {
            $this->additionalServices[$serviceCode] = $service;
        }

        return $this;
    }

    /**
     * Write files and return array with service names and related file content
     *
     * @param array $parameters
     * @param bool $write
     * @return array
     */
    public function dump(array $parameters, bool $write = true): array
    {
        $this->assemble();
        $filesByService = [];

        foreach ($this->additionalServices as $service) {
            $filesByService[$service->getName()] = $service->dump($parameters, $write);
        }

        return $filesByService;
    }

    private function assemble(): void
    {

    }
}
