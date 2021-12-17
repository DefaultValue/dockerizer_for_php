<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection as TemplateCollection;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class Runner implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'runner';

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
        return 'Composition main service (runner)';
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
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    ): string {
        $templateCode = $input->getOption(CompositionTemplate::OPTION_NAME);
        $template = $this->templateCollection->getProcessibleFile($templateCode);

throw new \Exception('Not implemetned');
        // @TODO: select which required and optional services to use
        // @TODO: this must be an option, so that service list can be provided without additional interaction

//        $question = new ChoiceQuestion(
//            '<question>Choose composition template to use:</question> ',
//            $this->templateCollection->getCodes()
//        );
//
//        return $questionHelper->ask($input, $output, $question);
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed &$value): void
    {

    }
}
