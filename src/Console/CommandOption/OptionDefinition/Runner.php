<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

class Runner implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'runner';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     */
    public function __construct(private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition)
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
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getQuestion(): ChoiceQuestion
    {
        $template = $this->composition->getTemplate();

        return new ChoiceQuestion(
            '<info>Select runner:</info> ',
            array_keys($template->getServices(Service::TYPE_RUNNER))
        );
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): string
    {
        $template = $this->composition->getTemplate();

        try {
            if ($template->getPreconfiguredServiceByName($value)->getType() !== Service::TYPE_RUNNER) {
                throw new \Exception();
            }
        } catch (\Throwable) {
            throw new OptionValidationException(
                "Template '{$template->getCode()}' does not have available runner with code '$value', or such runner does not exist."
            );
        }

        return $value;
    }
}
