<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command;

abstract class AbstractParameterAwareCommand extends \Symfony\Component\Console\Command\Command
{
    protected iterable $commandArguments;

    protected iterable $commandOptions;

    /**
     * @param iterable $commandArguments
     * @param iterable $commandOptions
     * @param string|null $name
     */
    public function __construct(
        iterable $commandArguments,
        iterable $commandOptions,
        string $name = null
    ) {
        parent::__construct($name);
        $this->commandArguments = $commandArguments;
        $this->commandOptions = $commandOptions;
    }
}
