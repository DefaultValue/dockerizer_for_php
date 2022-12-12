<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use Symfony\Component\Console\Input\InputOption;

class Force implements \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface
{
    public const OPTION_NAME = 'force';

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
        return 'f';
    }

    /**
     * @inheritDoc
     */
    public function getMode(): int
    {
        return InputOption::VALUE_NONE;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Force execution: erase, ignore requirements or warnings, etc.';
    }

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }
}
