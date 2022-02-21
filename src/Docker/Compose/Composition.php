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

    /**
     * @var Service[]
     */
    private array $servicesByName = [];

    private Template $template;

    private Service $runner;

    /**
     * @return Template
     */
    public function getTemplate(): Template
    {
        return $this->template;
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
     * @param string $serviceName
     * @return $this
     */
    public function addService(string $serviceName): self
    {
        /** @var Service $service */
        $service = $this->template->getPreconfiguredServiceByName($serviceName);
        //  @TODO: validate environment variables used by the service
        // $service->validate();

        if ($service->getType() === Service::TYPE_RUNNER) {
            if (isset($this->runner)) {
                throw new \RuntimeException(sprintf(
                    'Composition runner is already set. Old runner: %s. New runner: %s',
                    $this->runner->getName(),
                    $service->getName()
                ));
            }

            $this->runner = $service;
            $this->servicesByName[$service->getName()] = $service;

            // Dev tools is not an additional service. This yaml file is stored separately from the main file
            // Thus we do not add `$devTools` to `$this->additionalServices`
            if ($devTools = $this->getDevTools()) {
                $this->servicesByName[$devTools->getName()] = $devTools;
            }
        } else {
            $this->additionalServices[$service->getName()] = $service;
            $this->servicesByName[$service->getName()] = $service;
        }

        return $this;
    }

    /**
     * @param string $name
     * @return Service
     */
    public function getService(string $name): Service
    {
        return $this->servicesByName[$name];
    }

    /**
     * @return Service
     */
    public function getRunner(): Service
    {
        return $this->runner;
    }

    /**
     * @return Service[]
     */
    public function getAdditionalServices(): array
    {
        return $this->additionalServices;
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        $parameters = [
            'by_service' => [],
            'all' => [],
            'missed' => []
        ];

        foreach ($this->servicesByName as $service) {
            $serviceParameters = $service->getParameters();
            $parameters['by_service'][$service->getName()] = $service->getParameters();
            $parameters['all'][] = $serviceParameters['all'];
            $parameters['missed'][] = $serviceParameters['missed'];
        }

        $parameters['all'] = array_unique(array_merge(...$parameters['all']));
        $parameters['missed'] = array_unique(array_merge(...$parameters['missed']));

        return $parameters;
    }

    /**
     * @param string $parameter
     * @param mixed $value
     * @return void
     */
    public function setServiceParameter(string $parameter, mixed $value): void
    {
        foreach ($this->servicesByName as $service) {
            $service->setParameterIfMissed($parameter, $value);
        }
    }

    /**
     * @return Service|null
     */
    public function getDevTools(): ?Service
    {
        $devToolsKey = $this->runner->getName() . '_' . Service::CONFIG_KEY_DEV_TOOLS;

        return $this->getTemplate()->getPreconfiguredServiceByName($devToolsKey);
    }
}
