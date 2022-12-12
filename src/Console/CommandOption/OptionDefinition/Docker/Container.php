<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Running Docker container name
 */
class Container implements
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'container';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     */
    public function __construct(private \DefaultValue\Dockerizer\Docker\Docker $docker)
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
        return InputOption::VALUE_OPTIONAL;
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
    public function getDefault(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): mixed
    {
        // Ensure container exists and is running
        try {
            if ($value) {
                $this->docker->getContainerIp($value);
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
