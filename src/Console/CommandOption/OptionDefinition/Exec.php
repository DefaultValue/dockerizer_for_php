<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use Symfony\Component\Console\Input\InputOption;

class Exec implements \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface
{
    public const OPTION_NAME = 'exec';

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
        return 'e';
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
        return 'Execute a command instead of running it';
    }

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }
}
