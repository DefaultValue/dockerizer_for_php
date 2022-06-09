<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandArgument\Composition;

use DefaultValue\Dockerizer\Console\CommandArgument\CommandArgumentInterface;

class Template implements CommandArgumentInterface
{
    public const ARGUMENT_NAME = 'template';
}
