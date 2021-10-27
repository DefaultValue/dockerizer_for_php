<?php

declare(strict_types=1);

namespace App\Docker\Compose\Composition;

use Symfony\Component\Finder\Finder;

class TemplateList
{
    private string $compositionTemplatesDir;

    private string $projectDir;

    private static array $templateList;

    /**
     * @param string $projectDir
     * @param string $compositionTemplatesDir
     */
    public function __construct(
        string $projectDir,
        string $compositionTemplatesDir
    ) {
        $this->projectDir = $projectDir;
        $this->compositionTemplatesDir = $compositionTemplatesDir;
    }

    /**
     * Get composition templates
     *
     * @return array
     */
    public function getTemplatesList(): array
    {
        if (isset(self::$templateList)) {
            return self::$templateList;
        }

        self::$templateList = [];
        $files = Finder::create()->files()->in($this->projectDir . $this->compositionTemplatesDir)->name('*.yaml');

        foreach ($files as $file) {
            self::$templateList[$file->getBasename()] = $file->getRealPath();
        }

        return self::$templateList;
    }

    /**
     * Template object factory
     *
     * @param string $templateYaml
     * @return Template
     */
    public function getTemplate(string $templateYaml): Template
    {
        return new Template($this->getTemplatesList()[$templateYaml]);
    }
}
