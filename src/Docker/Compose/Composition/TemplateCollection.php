<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\Finder;

class TemplateCollection
{
    private static array $templates;

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
     * @return Template[]
     */
    public function getTemplates(): array
    {
        if (!empty(self::$templates)) {
            return self::$templates;
        }

        $dir = $this->projectDir . $this->dir;

        foreach (Finder::create()->files()->in($dir)->name('*.yaml') as $fileInfo) {
            self::$templates[$fileInfo->getBasename()] = new Template($fileInfo);
        }

        ksort(self::$templates);

        return self::$templates;
    }

    /**
     * @param string $templateYaml
     * @return Template
     */
    public function getTemplate(string $templateYaml): Template
    {
        if (empty(self::$templates)) {
            $this->getTemplates();
        }

        if (!isset(self::$templates[$templateYaml])) {
            throw new \InvalidArgumentException("Service `$templateYaml` does not exist");
        }

        return self::$templates[$templateYaml];
    }
}
