<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;

interface ValidatableOptionInterface
{
    /**
     * Validate entered or pre-defined value (user may pass invalid value via input)
     *
     * @param null|string|int $value
     * @return mixed
     * @throws OptionValidationException
     */
    public function validate(mixed $value): mixed;
}
