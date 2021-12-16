<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

class Template extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile
    implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const ROOT_NODE = 'app';
    public const DESCRIPTION = 'description';
    public const SUPPORTED_PACKAGES = 'supported_packages';
    public const SUPPORTED_PACKAGE_EQUALS_OR_GREATER = 'equals_or_greater';
    public const SUPPORTED_PACKAGE_LESS_THAN = 'less_than';
    public const COMPOSITION = 'composition';
    public const RUNNERS = 'runners';

    /**
     * @var array $templateData
     */
    private array $templateData;

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->templateData[self::DESCRIPTION];
    }

    /**
     * @return array
     */
    public function getSupportedPackages(): array
    {
        return $this->templateData[self::SUPPORTED_PACKAGES] ?? [];
    }

    public function getRunners(): array
    {
        // @TODO: validate if runners and other services are available, report if not
        return $this->templateData[self::COMPOSITION][self::RUNNERS];
    }

    public function getRequiredServices()
    {

    }

    public function getOptionalServices()
    {

    }

    /**
     * YAML file validation. Would be great to implement YAML schema validation based on
     * https://github.com/shaggy8871/Rx/tree/master/php or some newer library if it exists...
     *
     * @return void
     * @throws \Exception
     */
    protected function validate(): void
    {
        // @TODO: should we validate all services at this stage as well? In this case we can tell which template
        // causes the issue
        $templateData = Yaml::parseFile($this->getFileInfo()->getRealPath());

        if (count($templateData) > 1 || !isset($templateData['app'])) {
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

        $this->templateData = $templateData['app'];
    }
}
