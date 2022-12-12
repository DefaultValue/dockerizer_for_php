<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Compose;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * docker-compose service name
 *
 * @TODO: make it possible to set composition and ask a question to select a service from the composition
 * For now there is no way to do this and thus nohow to validate the option value
 */
class Service implements
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface
{
    public const OPTION_NAME = 'service';

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
        return 's';
    }

    /**
     * @inheritDoc
     */
    public function getMode(): int
    {
        return InputOption::VALUE_OPTIONAL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'docker-compose service name';
    }

    /**
     * @inheritDoc
     */
    public function getDefault(): string
    {
        return '';
    }
}
