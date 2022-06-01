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
class UniversalReusableOption implements
    \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const NAME_PREFIX = 'with-';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param string $name
     * @param mixed $default
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private string $name = '',
        private mixed $default = null
    ) {
    }

    /**
     * @param string $name
     * @param mixed $default - used to have some value in case it is not passed via input
     * @return $this
     */
    public function initialize(string $name, mixed $default = null): static
    {
        return new static($this->composition, $name, $default);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::NAME_PREFIX . $this->name;
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
        return InputOption::VALUE_OPTIONAL;
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
        return $this->default;
    }

    /**
     * @inheritDoc
     */
    public function getQuestion(): ?Question
    {
        if (!$this->composition->isParameterMissed($this->name)) {
            return null;
        }

        $question = "<info>Parameter {{{$this->name}}} is required for the following services:</info>\n";
        $parameterDefinedFor = [];

        foreach ($this->composition->getParameters()['by_service'] as $serviceName => $parameters) {
            if (!isset($parameters[$this->name])) {
                continue;
            }

            $service = $this->composition->getService($serviceName);

            try {
                $service->getParameterValue($this->name);
                $parameterDefinedFor[] = $serviceName;
            } catch (\Exception) {
                foreach ($parameters[$this->name] as $file) {
                    $question .= "- <info>$serviceName</info> in file $file\n";
                }
            }
        }

        if (!empty($parameterDefinedFor)) {
            $question .= "\nOther services use the following value for this parameter:\n";

            foreach ($parameterDefinedFor as $serviceName) {
                $service = $this->composition->getService($serviceName);
                $question .= "- <info>$serviceName</info>: {$service->getParameterValue($this->name)}\n";
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
        // No value, but all services have the value set as expected
        if (!$value && !$this->composition->isParameterMissed($this->name)) {
            return $this->composition->getParameterValue($this->name);
        }

        return $value;
    }
}
