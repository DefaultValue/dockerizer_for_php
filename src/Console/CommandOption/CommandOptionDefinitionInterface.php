<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

interface CommandOptionDefinitionInterface
{
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

    /**
     * @param $value
     * @return bool
     */
    public function validate($value): bool;
}
