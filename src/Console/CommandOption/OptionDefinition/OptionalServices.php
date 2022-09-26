<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Very simple way to ask for additional services, all groups at once.
 * Ideally, it would be better to ask for every group one by one to eliminate the case when multiple services from the
 * same group are selected
 */
class OptionalServices extends \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Service\AbstractService
{
    public const OPTION_NAME = 'optional-services';

    public const SERVICE_TYPE = Service::TYPE_OPTIONAL;

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
        return 'List of required services (comma-separated): --optional-service-redis=redis_5.0';
    }

    /**
     * @return ?ChoiceQuestion
     */
    public function getQuestion(): ?ChoiceQuestion
    {
        if ($question = parent::getQuestion()) {
            // Replace default validator to allow empty value without showing an error
            $question->setValidator([$this, 'validate']);
        }

        return $question;
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): array
    {
        // Empty value is fine for optional services
        if ($value === null || $value === '') {
            return array_values($this->valueByGroup);
        }

        return parent::validate($value);
    }
}
