<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Finder\Finder;

/**
 * Collection of service definitions from ./templates/services/ or from the repositories
 */
class Collection
{
    private static array $services;

    /**
     * @param \DefaultValue\Dockerizer\DependencyInjection\Factory $factory
     * @param string $projectDir
     * @param string $serviceDir
     */
    public function __construct(
        private \DefaultValue\Dockerizer\DependencyInjection\Factory $factory,
        private string $projectDir,
        private string $serviceDir
    ) {
    }

    /**
     * @return array
     */
    public function getServices(): array
    {
        if (!empty(self::$services)) {
            return self::$services;
        }

        $dir = $this->projectDir . $this->serviceDir;

        foreach (Finder::create()->files()->in($dir)->name(['*.yaml', '*.yml']) as $fileInfo) {
            $serviceCode = $fileInfo->getFilenameWithoutExtension();
            /** @var Service $service */
            $service = $this->factory->get(Service::class);
            $service->setCode($serviceCode)
                ->setFileInfo($fileInfo);
            self::$services[$serviceCode] = $service;
        }

        ksort(self::$services);

        return self::$services;
    }

    /**
     * @param string $serviceYaml
     * @return Service
     */
    public function getService(string $serviceYaml): Service
    {
        if (empty(self::$services)) {
            $this->getServices();
        }

        if (!isset(self::$services[$serviceYaml])) {
            throw new \InvalidArgumentException("Service `$serviceYaml` does not exist");
        }

        return self::$services[$serviceYaml];
    }
}
