<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Template extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile implements
    \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const CONFIG_KEY_ROOT_NODE = 'app';
    public const CONFIG_KEY_DESCRIPTION = 'description';
    public const CONFIG_KEY_SUPPORTED_PACKAGES = 'supported_packages';
    public const CONFIG_KEY_COMPOSITION = 'composition';
    public const CONFIG_KEY_SERVICE_CODE = 'service';

    private array $templateData;

    private array $preconfiguredServicesByName;

    private array $preconfiguredServices = [
        Service::TYPE_REQUIRED => [],
        Service::TYPE_OPTIONAL => []
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Collection $serviceCollection
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Collection $serviceCollection
    ) {
    }

    /**
     * @param SplFileInfo $fileInfo
     * @return void
     */
    public function init(SplFileInfo $fileInfo): void
    {
        parent::init($fileInfo);
        $templateData = Yaml::parseFile($this->getFileInfo()->getRealPath());
        $this->validate($templateData);
        $this->templateData = $templateData[self::CONFIG_KEY_ROOT_NODE];
        $this->preconfigure();
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->templateData[self::CONFIG_KEY_DESCRIPTION];
    }

    /**
     * @return array
     */
    public function getSupportedPackages(): array
    {
        return $this->templateData[self::CONFIG_KEY_SUPPORTED_PACKAGES] ?? [];
    }

    /**
     * @param string $type
     * @return array
     */
    public function getServices(string $type): array
    {
        return $this->preconfiguredServices[$type];
    }

    /**
     * @param string $name
     * @return Service|null
     */
    public function getPreconfiguredServiceByName(string $name): ?Service
    {
        return $this->preconfiguredServicesByName[$name] ?? null;
    }

    /**
     * @param string $parameter
     * @return null|string|int|float
     */
    public function getPreconfiguredParameterValue(string $parameter): null|string|int|float
    {
        return $this->templateData[Service::CONFIG_KEY_PARAMETERS][$parameter] ?? null;
    }

    /**
     * YAML file validation. Would be great to implement YAML schema validation based on
     * https://github.com/shaggy8871/Rx/tree/master/php or some newer library if it exists...
     *
     * @param array $data
     * @return void
     */
    protected function validate(array $data): void
    {
        // @TODO: should we validate all services at this stage as well? In this case we can tell which template
        // causes the issue
        if (count($data) > 1 || !isset($data[self::CONFIG_KEY_ROOT_NODE])) {
            throw new \DomainException(
                'Only one add definition is allowed per template file in ' . $this->getFileInfo()->getRealPath()
            );
        }

        /*
        unset(
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_NAME],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_FROM],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_TO],
        );
        */
    }

    /**
     * @return void
     */
    private function preconfigure(): void
    {
        foreach ($this->templateData[self::CONFIG_KEY_COMPOSITION] as $serviceType => $services) {
            foreach ($services as $groupName => $groupServices) {
                $this->preconfiguredServices[$serviceType][$groupName] = [];

                foreach ($groupServices as $preconfiguredServiceName => $config) {
                    if (isset($this->preconfiguredServicesByName[$preconfiguredServiceName])) {
                        throw new \InvalidArgumentException(sprintf(
                            'Template \'%s\' already contains service \'%s\'',
                            $this->getCode(),
                            $preconfiguredServiceName
                        ));
                    }

                    $serviceCode = $config[self::CONFIG_KEY_SERVICE_CODE];
                    unset($config[self::CONFIG_KEY_SERVICE_CODE]);
                    /** @var Service $service */
                    // Service is stateful: it remembers its parameters and dev tools. Must clone it.
                    $service = clone $this->serviceCollection->getByCode($serviceCode);
                    $config[Service::TYPE] = $serviceType;
                    $config[Service::CONFIG_KEY_PARAMETERS] = array_merge(
                        $this->templateData[Service::CONFIG_KEY_PARAMETERS],
                        $config[Service::CONFIG_KEY_PARAMETERS] ?? []
                    );

                    $service->preconfigure($preconfiguredServiceName, $config);
                    $this->preconfiguredServicesByName[$preconfiguredServiceName] = $service;
                    $this->preconfiguredServices[$serviceType][$groupName][$service->getName()] = $service;
                }
            }
        }
    }
}
