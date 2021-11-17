<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Template
{
    public const ROOT_NODE = 'app';
    public const NAME = 'name';
    public const SUPPORTED_PACKAGES = 'supported_packages';
    public const SUPPORTED_PACKAGE_EQUALS_OR_GREATER = 'equals_or_greater';
    public const SUPPORTED_PACKAGE_LESS_THAN = 'less_than';
    public const COMPOSITION = 'composition';
    public const RUNNERS = 'runners';

    /**
     * @var mixed
     */
    private array $templateData;

    /**
     * @param SplFileInfo $fileInfo
     * @throws \Exception
     */
    public function __construct(
        private SplFileInfo $fileInfo
    ) {
        $this->templateData = $this->validate($fileInfo);
    }

    public function getName(): string
    {
        return $this->templateData[self::NAME];
    }

    public function getSupportedPackages(): ?array
    {
        return $this->templateData[self::SUPPORTED_PACKAGES] ?? [];
    }

    public function getRunners(): array
    {
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
     * @param SplFileInfo $absolutePath
     * @return mixed
     * @throws \Exception
     */
    private function validate(SplFileInfo $absolutePath)
    {
        // @TODO: should we validate all services at this stage as well? In this case we can tell which template
        // causes the issue
        $templateData = Yaml::parseFile($this->fileInfo->getRealPath());

        if (count($templateData) > 1 || !isset($templateData['app'])) {
            throw new \Exception('Only one add definition is allowed per template file in ' . $absolutePath);
        }

        /*
        unset(
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_NAME],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_FROM],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_TO],
        );
        */

        return $templateData['app'];
    }
}
