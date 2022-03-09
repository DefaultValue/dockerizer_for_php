<?php

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
    public const CONFIG_KEY_SUPPORTED_PACKAGE_EQUALS_OR_GREATER = 'equals_or_greater';
    public const CONFIG_KEY_SUPPORTED_PACKAGE_LESS_THAN = 'less_than';
    public const CONFIG_KEY_COMPOSITION = 'composition';
    public const CONFIG_KEY_RUNNERS = 'runners';
    public const CONFIG_KEY_SERVICE_CODE = 'service';

    private array $templateData;

    private array $preconfiguredServices = [
        Service::TYPE_RUNNER => [],
        Service::TYPE_DEV_TOOLS => [],
        Service::TYPE_REQUIRED => [],
        Service::TYPE_OPTIONAL => []
    ];

    private array $preconfiguredServicesByName;

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
        foreach ($this->templateData[self::CONFIG_KEY_COMPOSITION] as $configKey => $services) {
            if ($configKey === self::CONFIG_KEY_RUNNERS) {
                $this->preconfiguredServices[Service::TYPE_RUNNER]
                    = $this->preconfigureServices(Service::TYPE_RUNNER, $services);
            } else {
                foreach ($services as $groupName => $groupServices) {
                    $this->preconfiguredServices[$configKey][$groupName]
                        = $this->preconfigureServices($configKey, $groupServices);
                }
            }
        }
    }

    /**
     * @param string $type
     * @param array $serviceConfigs
     * @return array
     */
    private function preconfigureServices(string $type, array $serviceConfigs): array
    {
        $services = [];

        foreach ($serviceConfigs as $preconfiguredServiceName => $config) {
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
            $service = clone $this->serviceCollection->getByCode($serviceCode);
            $config[Service::TYPE] = $type;
            $service->preconfigure($preconfiguredServiceName, $config);
            $this->preconfiguredServicesByName[$preconfiguredServiceName] = $service;
            $services[$preconfiguredServiceName] = $service;
            $this->preconfigureDevTools($service, $config);
        }

        return $services;
    }

    /**
     * Initialize dev tools as a service.
     * Dev tools also may have options to enter, so need to deal with this like an individual service
     *
     * @param Service $service
     * @param array $parentConfig
     * @return void
     */
    private function preconfigureDevTools(Service $service, array $parentConfig): void
    {
        if (!isset($parentConfig[Service::CONFIG_KEY_DEV_TOOLS])) {
            return;
        }

        $devToolsService = clone $this->serviceCollection->getByCode(
            $parentConfig[Service::CONFIG_KEY_DEV_TOOLS]
        );
        $devToolsConfig = [
            Service::TYPE => Service::TYPE_DEV_TOOLS,
            Service::CONFIG_KEY_PARAMETERS => $parentConfig[Service::CONFIG_KEY_PARAMETERS] ?? []
        ];

        $devToolsPreconfiguredName = $service->getName() . '_' . Service::CONFIG_KEY_DEV_TOOLS;
        $devToolsService->preconfigure($devToolsPreconfiguredName, $devToolsConfig);
        $this->preconfiguredServices[Service::TYPE_DEV_TOOLS][$devToolsPreconfiguredName] = $devToolsService;
        $this->preconfiguredServicesByName[$devToolsPreconfiguredName] = $devToolsService;
    }
}
