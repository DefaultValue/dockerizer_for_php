<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Running Docker container name
 */
class Container implements
    \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'container';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Container $dockerContainer
     */
    public function __construct(private \DefaultValue\Dockerizer\Docker\Container $dockerContainer)
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
        return 'c';
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
        return 'Docker container name';
    }

    /**
     * @inheritDoc
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getQuestion(): Question
    {
        return new Question(
            '<info>Enter Docker container name:</info> '
        );
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): mixed
    {
        // Ensure container exists and is running
        try {
            if ($value) {
                $this->dockerContainer->getIp($value);
            }
        } catch (ProcessFailedException $e) {
            throw new OptionValidationException(sprintf(
                'Docker container with name \'%s\' does not exist or is not running. Process error: %s',
                $value,
                $e->getMessage()
            ));
        }

        return $value;
    }
}
