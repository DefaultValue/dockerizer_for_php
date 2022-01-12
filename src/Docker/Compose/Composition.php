<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;

/**
 * A STATEFUL container for generating a new composition and assembling composition files.
 * This IS a singleton that keeps its state during a single application run
 */
class Composition
{
    /**
     * @var Service[]
     */
    private array $additionalServices = [];

    private Template $template;

    private Service $runner;

    /**
     * @return Template
     */
    public function getTemplate(): Template
    {
        return$this->template;
    }

    /**
     * @param Template $template
     * @return $this
     */
    public function setTemplate(Template $template): self
    {
        if (isset($this->template)) {
            throw new \DomainException('Composition template is already set!');
        }

        $this->template = $template;

        return $this;
    }

    /**
     * @param Service $service
     * @return $this
     */
    public function addService(Service $service): self
    {
        //  @TODO: validate environment variables used by the service
        // $service->validate();
        $serviceCode = $service->getCode();

        if (isset($this->additionalServices[$serviceCode])) {
            throw new \RuntimeException("Service $serviceCode already exists in the composition");
        }

        if ($service->getType() === Service::TYPE_RUNNER) {
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
        $this->assemble($parameters);
        $filesByService = [];

        foreach ($this->additionalServices as $service) {
            // @TODO: get full file path instead
            $filesByService[$service->getCode()][] = $service->dumpServiceFile($parameters, $write);
        }

        return $filesByService;
    }

    private function assemble(array $parameters): void
    {
        $runnerYaml = $this->runner->dumpServiceFile($parameters);

        // @TODO: this is not a runner name!!!! must choose from the runners list and save the link code as well!
        $this->runner->getCode();

        foreach ($this->additionalServices as $service) {
            // $runnerYaml = $this->merge($runnerYaml, $service->dumpServiceFile($parameters));
        }


        // process links
        //
    }
}
