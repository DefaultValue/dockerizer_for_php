<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

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

    /**
     * @param string $template
     * @throws \Exception
     */
    public function __construct(
        string $template
    ) {
        $this->template = $this->validate($template);
    }

    public function getName(): string
    {
        return $this->template->getTag('name');
    }

    public function getVersion(): string
    {
        return $this->template->getTag('name');
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
     * @param string $template
     * @return mixed
     * @throws \Exception
     */
    private function validate(string $template)
    {
        $templateData = Yaml::parseFile($template);

        if (count($templateData) > 1 || !isset($templateData['app'])) {
            throw new \Exception('Only one add definition is allowed per template file in ' . $template);
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
