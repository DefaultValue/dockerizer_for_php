<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\Finder;

class ServiceCollection
{
    private static array $services;

    /**
     * @param string $projectDir
     * @param string $dir
     */
    public function __construct(
        private string $projectDir,
        private string $dir
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

        $dir = $this->projectDir . $this->dir;

        foreach (Finder::create()->files()->in($dir)->name('*.yaml') as $fileInfo) {
            self::$services[$fileInfo->getBasename()] = new Service($fileInfo);
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
