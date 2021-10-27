<?php

declare(strict_types=1);

namespace App\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

class Template
{
    private const TEMPLATE_ROOT = 'app';
    private const TEMPLATE_NAME = 'name';
    private const TEMPLATE_VERSION = 'version';
    private const TEMPLATE_VERSION_FROM = 'from';
    private const TEMPLATE_VERSION_TO = 'to';

    /**
     * @var mixed
     */
    private $template;

    public function __construct(
        string $template
    ) {
        $this->template = Yaml::parseFile($template);
        $this->selfValidate();
    }

    public function getName(): string
    {
        return $this->template;
    }

    public function getRequiredServices()
    {

    }

    public function getOptionalServices()
    {

    }

    public function load(string $template)
    {

    }

    private function selfValidate()
    {
        $templateData = $this->template;

        if (count($templateData)) {

        }

        unset(
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_NAME],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_FROM],
            $templateData[self::TEMPLATE_ROOT][self::TEMPLATE_VERSION][self::TEMPLATE_VERSION_TO],
        );

        $foo = false;
    }
}
