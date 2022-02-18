<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection as TemplateCollection;
use PHLAK\SemVer\Exceptions\InvalidVersionException;
use PHLAK\SemVer\Version;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

class CompositionTemplate implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'template';

    /**
     * @param TemplateCollection $templateCollection
     */
    public function __construct(private TemplateCollection $templateCollection)
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::OPTION_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getShortcut(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getMode(): int
    {
        return InputOption::VALUE_REQUIRED;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Composition template';
    }

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @return ChoiceQuestion
     */
    public function getQuestion(): ChoiceQuestion
    {
        $questionText = $this->getTemplateRecommendation();

        return new ChoiceQuestion(
            $questionText . PHP_EOL . '<info>Select composition template to use:</info> ',
            $this->templateCollection->getCodes()
        );
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): string
    {
        try {
            $this->templateCollection->getByCode($value);
        } catch (\Exception $e) {
            throw new \RuntimeException("Not a valid composition template: $value\n{$e->getMessage()}");
        }

        return $value;
    }

    /**
     * Get template recommendations.
     * Versions can be parsed and analyzed > project is probably supported by template
     * Package matches, but versions are not fully defined > suggest this template for the project
     * Versions are defined and do not match > skip template
     * This can potentially be moved elsewhere
     *
     * @return string
     */
    public function getTemplateRecommendation(): string
    {
        // @TODO: Filesystem\Firewall
        if (!file_exists('composer.json')) {
            return '';
        }

        $templateRecommendations = '';

        // @TODO: support other files, not only `composer.json` (`package.json` etc.)
        try {
            // @TODO: Filesystem\Firewall
            $composerJson = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!isset($composerJson['require'])) {
            return '';
        }

        $requiredProjectPackages = [];

        foreach ($composerJson['require'] as $packageName => $packageVersion) {
            $requiredProjectPackages[$packageName] = $this->parseVersion($packageVersion);
        }

        $potentiallySuitableProjectTemplates = [];
        $recommendedTemplates = [];

        foreach ($this->templateCollection as $template) {
            $templateIsSuitableFor = [];
            $templateIsRecommendedFor = [];

            foreach ($template->getSupportedPackages() as $supportedPackage => $supportedVersions) {
                if (!isset($requiredProjectPackages[$supportedPackage])) {
                    continue;
                }

                $minVersionNumber = $supportedVersions[Template::CONFIG_KEY_SUPPORTED_PACKAGE_EQUALS_OR_GREATER] ?? '';
                $maxVersionNumber = $supportedVersions[Template::CONFIG_KEY_SUPPORTED_PACKAGE_LESS_THAN] ?? '';

                if (
                    !$requiredProjectPackages[$supportedPackage]
                    || !($minVersion = $this->parseVersion($minVersionNumber))
                    || !($maxVersion = $this->parseVersion($maxVersionNumber))
                ) {
                    $templateIsSuitableFor[] = $supportedPackage;

                    continue;
                }

                if (
                    $minVersion->lte($requiredProjectPackages[$supportedPackage])
                    && $maxVersion->gt($requiredProjectPackages[$supportedPackage])
                ) {
                    $templateIsRecommendedFor[] = $supportedPackage;
                }
            }

            $potentiallySuitableProjectTemplates[$template->getCode()] = $templateIsSuitableFor;
            $recommendedTemplates[$template->getCode()] = $templateIsRecommendedFor;
        }

        $potentiallySuitableProjectTemplates = array_filter($potentiallySuitableProjectTemplates);
        $recommendedTemplates = array_filter($recommendedTemplates);

        if ($potentiallySuitableProjectTemplates) {
            $templateRecommendations .= 'Potentially suitable templates are:' . PHP_EOL;

            foreach ($potentiallySuitableProjectTemplates as $templateCode => $packages) {
                $templateRecommendations .= sprintf('- %s (%s)', $templateCode, implode(', ', $packages)) . PHP_EOL;
            }
        }

        if ($recommendedTemplates) {
            $templateRecommendations .= PHP_EOL . 'Recommended templates are:' . PHP_EOL;

            foreach ($recommendedTemplates as $templateCode => $packages) {
                $templateRecommendations .= sprintf('- %s (%s)', $templateCode, implode(', ', $packages));
            }
        }

        return $templateRecommendations . PHP_EOL;
    }

    /**
     * @param string $packageVersion
     * @return false|Version
     */
    private function parseVersion(string $packageVersion): bool|Version
    {
        try {
            return new Version($packageVersion);
        } catch (InvalidVersionException) {
            // Just skip package if we can't parse version
            return false;
        }
    }
}
