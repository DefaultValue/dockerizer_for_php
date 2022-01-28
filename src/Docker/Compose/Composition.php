<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Yaml\Yaml;

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
     * [
     *     'parameter_name' => ['service_1', 'service_2', 'service_3]
     * ]
     * @return array
     */
    public function getMissedParameters(): array
    {
        // @TODO: including dev tools file(s)
        $parameters = [];

        /** @var Service $service */
        foreach ($this->getSelectedServices() as $service) {
            $parameters[] = $service->getMissedParameters();
        }

        return array_unique(array_merge(...$parameters));
    }

    public function setServiceParameter(string $key, mixed $value)
    {
        /** @var Service $service */
        foreach (array_merge([$this->runner], $this->additionalServices) as $service) {
            $service->setParameterIfMissed($key, $value);
        }
    }

    /**
     * Write files and return array with service names and related file content
     *
     * @param bool $write
     * @return array
     */
    public function dump(bool $write = true): array
    {
        // @TODO: Filesystem/Firewall
        $content = $this->runner->getPreconfiguredMainFile();
        $processedContent = $this->runner->getProcessedContent();

        foreach ($this->additionalServices as $service) {
//            $service
        }


        $yaml = Yaml::parse($content);

        $dumpedContent = Yaml::dump($yaml, 32, 2);

        $processedContent->dump();

        file_put_contents('/home/maksymz/misc/apps/dockerizer_for_php_3/test_56/test.yaml', $dumpedContent);
        $foo = false;


        return $filesByService;
    }

    /**
     * @return array
     */
    private function getSelectedServices(): array
    {
        $services = array_merge([$this->runner], $this->additionalServices);
        $devToolsKey = $this->runner->getName() . '_' . Service::CONFIG_KEY_DEV_TOOLS;

        if ($devTools = $this->getTemplate()->getPreconfiguredServiceByName($devToolsKey)) {
            $services[] = $devTools;
        }

        return $services;
    }
}
