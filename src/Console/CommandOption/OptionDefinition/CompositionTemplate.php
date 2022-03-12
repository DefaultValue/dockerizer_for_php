<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection as TemplateCollection;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

class CompositionTemplate implements
    \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'template';

    private string $package = '';

    private string $version = '';

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
            throw new OptionValidationException("Not a valid composition template: $value\n{$e->getMessage()}");
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

        if ($this->package && $this->version) {
            // Use preset data
            $composerJson = [
                'name' => $this->package,
                'version' => $this->version
            ];
            $composerLock = [];
        } else {
            // Parse composer.json and composer.lock if present
            $composerJson = [];
            $composerLock = [];

            // @TODO: support other files, not only `composer.json` (`package.json` etc.)
            try {
                // @TODO: Filesystem\Firewall
                if (file_exists('composer.json')) {
                    $composerJson = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
                }

                if (file_exists('composer.lock')) {
                    $composerLock = json_decode(file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
                }
            } catch (\JsonException) {
                return '';
            }
        }

        $recommendedTemplates = isset($composerJson['name'], $composerJson['version'])
            ? $this->templateCollection->getRecommendedTemplates($composerJson['name'], $composerJson['version'])
            : [];

        $suitableTemplates = [];

        if (isset($composerJson['require'], $composerLock['packages'])) {
            $lockedPackages = [];

            foreach ($composerLock['packages'] as $packageMeta) {
                if (isset($composerJson['require'][$packageMeta['name']])) {
                    $lockedPackages[$packageMeta['name']] = ltrim($packageMeta['version'], 'v');
                }
            }

            $suitableTemplates = $this->templateCollection->getSuitableTemplates($lockedPackages);
        }

        $suitableTemplates = array_diff_key($suitableTemplates, $recommendedTemplates);

        if ($suitableTemplates) {
            $templateRecommendations .= 'Potentially suitable templates are:' . PHP_EOL;

            foreach ($suitableTemplates as $templateCode => $packages) {
                $templateRecommendations .= sprintf('- %s (%s)', $templateCode, implode(', ', $packages)) . PHP_EOL;
            }
        }

        if ($recommendedTemplates) {
            $templateRecommendations .= PHP_EOL . 'Recommended templates are:' . PHP_EOL;
            $templateRecommendations .= implode("\n", array_map(
                static fn ($templateCode) => '- ' . $templateCode,
                array_keys($recommendedTemplates)
            ));
        }

        return $templateRecommendations . PHP_EOL;
    }

    /**
     * @param string $package
     * @param string $version
     * @return void
     */
    public function setPackage(string $package, string $version): void
    {
        $this->package = $package;
        $this->version = $version;
    }
}
