<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;

/**
 * Value Object. Setters always return new instance to avoid saving object state.
 * Used to provide any option, for example missed parameters for the `composition:build-from-template` command.
 * It is not possible to predict a number and names of missed options, so we need a reusable option for such cases.
 *
 * P.S.: Let me know if you know a better way of implementing this
 */
class UniversalReusableOption implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    private const NAME_PREFIX = 'with-';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private ?string $name = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::NAME_PREFIX . $this->name;
    }

    /**
     * @param string $name
     * @return UniversalReusableOption
     */
    public function setName(string $name): UniversalReusableOption
    {
        return new self($this->composition, $name);
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
        return 'Set ' . $this->getName();
    }

    /**
     * @inheritDoc
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getQuestion(): Question
    {
        $question = "<question>Parameter '{$this->name}' is required for the following services:</question>\n";

        foreach ($this->composition->getParameters()['by_service'] as $service => $parameters) {
            foreach ($parameters['by_file'][$this->name] as $file) {
                $question .= "- <info>$service</info> in file $file\n";
            }
        }

        $question .= "> ";

        return new Question($question);
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): mixed
    {
        return $value;
    }
}