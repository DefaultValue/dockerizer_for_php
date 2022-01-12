<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;

class Domains implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'domains';

    /**
     * @param \DefaultValue\Dockerizer\Validation\Domain $domainValidator
     */
    public function __construct(private \DefaultValue\Dockerizer\Validation\Domain $domainValidator)
    {
    }

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
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getMode(): int
    {
        return InputOption::VALUE_REQUIRED;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Domains list (space-separated)';
    }

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @return Question
     */
    public function getQuestion(): Question {
        return new Question(
            '<question>Enter space-separated list of domains (including non-www and www version if needed):</question> '
        );
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): array
    {
        $value = $this->normalize($value);

        foreach ($value as $domain) {
            if (!$this->domainValidator->isValid($domain)) {
                throw new OptionValidationException("Not a valid domain name: $domain");
            }
        }

        return $value;
    }

    /**
     * @param mixed $domains
     * @return array
     */
    private function normalize(mixed $domains): array
    {
        if (!is_array($domains)) {
            // Cast to string in case it is NULL
            $domains = explode(OptionDefinitionInterface::VALUE_SEPARATOR, (string) $domains);
        }

        return array_filter($domains);
    }
}
