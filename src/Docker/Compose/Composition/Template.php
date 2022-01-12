<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Template extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile
    implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const CONFIG_KEY_ROOT_NODE = 'app';
    public const CONFIG_KEY_DESCRIPTION = 'description';
    public const CONFIG_KEY_SUPPORTED_PACKAGES = 'supported_packages';
    public const CONFIG_KEY_SUPPORTED_PACKAGE_EQUALS_OR_GREATER = 'equals_or_greater';
    public const CONFIG_KEY_SUPPORTED_PACKAGE_LESS_THAN = 'less_than';
    public const CONFIG_KEY_COMPOSITION = 'composition';
    public const CONFIG_KEY_RUNNERS = 'runners';
    public const CONFIG_KEY_REQUIRED_SERVICES = 'required';
    public const CONFIG_KEY_OPTIONAL_SERVICES = 'optional';
    public const CONFIG_KEY_SERVICE_CODE = 'service';

    private array $templateData;

    private array $preconfiguredServices;

    private array $preconfiguredServicesByCode;

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
        $this->preconfigureServices();
    }

    /**
     * @return void
     */
    private function preconfigureServices(): void
    {
        foreach ($this->templateData[self::CONFIG_KEY_COMPOSITION] as $serviceType => $services) {
            $this->preconfiguredServices[$serviceType] = [];

            if ($serviceType === self::CONFIG_KEY_RUNNERS) {
                $services = [$serviceType => $services];
            }

            foreach ($services as $groupCode => $group) {
                $this->preconfiguredServices[$serviceType][$groupCode] = [];

                foreach ($group as $preconfiguredServiceCode => $config) {
                    if (isset($this->preconfiguredServicesByCode[$preconfiguredServiceCode])) {
                        throw new \InvalidArgumentException(sprintf(
                            'Template \'%s\' already contains service \'%s\'',
                            $this->getCode(),
                            $preconfiguredServiceCode
                        ));
                    }

                    $serviceCode = $config[self::CONFIG_KEY_SERVICE_CODE];
                    unset($config[self::CONFIG_KEY_SERVICE_CODE]);
                    /** @var Service $service */
                    $service = clone $this->serviceCollection->getByCode($serviceCode);
                    $config[Service::TYPE] = $serviceType;
                    $service->preconfigure($config);
                    $this->preconfiguredServices[$serviceType][$groupCode][$preconfiguredServiceCode] = $service;
                    $this->preconfiguredServicesByCode[$preconfiguredServiceCode] = $service;
                }
            }
        }
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
     * @param string $code
     * @return Service
     */
    public function getRunnerByCode(string $code): Service
    {
        return $this->preconfiguredServices[self::CONFIG_KEY_RUNNERS][self::CONFIG_KEY_RUNNERS][$code];
    }

    public function getRunners(): array
    {
        return $this->preconfiguredServices[self::CONFIG_KEY_RUNNERS][self::CONFIG_KEY_RUNNERS];
    }

    public function getRequiredServices()
    {

    }

    public function getOptionalServices()
    {

    }

    public function getPreconfiguredServiceByCode(string $code): Service
    {
        return $this->preconfiguredServicesByCode[$code];
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
}
