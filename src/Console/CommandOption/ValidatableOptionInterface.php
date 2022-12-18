<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;

/**
 * Use custom validation mechanism instead of `$question->setValidator()` because options may have complex behavior:
 * - Automatically populate some values. For example, automatically select a single value
 *   for a group of required services in RequiredServices
 * - Skip validation of optional services, allowing to have empty list of services
 */
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
