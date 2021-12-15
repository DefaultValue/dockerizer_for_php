<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;

interface ValidatableOptionInterface
{
    /**
     * @param null|string|int $value
     * @return void
     * @throws OptionValidationException
     */
    public function validate(mixed &$value): void;
}
