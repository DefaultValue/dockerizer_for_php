<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection as TemplateCollection;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
     * @return void
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array ...$arguments
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        ...$arguments
    ): string {
        $question = new ChoiceQuestion(
            '<question>Choose composition template to use:</question> ',
            $this->templateCollection->getCodes()
        );

        return $questionHelper->ask($input, $output, $question);
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed &$value): void
    {
        try {
            $this->templateCollection->getFile($value);
        } catch (\Exception $e) {
            throw new OptionValidationException("Not a valid composition template: $value");
        }
    }
}
