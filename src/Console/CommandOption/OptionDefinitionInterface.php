<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

interface OptionDefinitionInterface
{
    /**
     * For options like --domains='foo.com www.foo.com bar.com www.bar.com baz.com www.baz.com'
     */
    public const VALUE_SEPARATOR = ' ';

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getShortcut(): string;

    /**
     * @return int
     */
    public function getMode(): int;

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * @return mixed
     */
    public function getDefault(): mixed;
}
