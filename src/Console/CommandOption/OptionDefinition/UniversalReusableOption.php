<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;

/**
 * Value Object. Setters always return new instance to avoid saving object state.
 * Used to provide any option, for example missed parameters for the `composition:build-from-template` command.
 * It is not possible to predict a number and names of missed options, so we need a reusable option for such cases.
 * This class is not planned to be extendable because it adds quite tricky functionality
 *
 * P.S.: Let me know if you know a better way of implementing this
 */
final class UniversalReusableOption implements
    \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const NAME_PREFIX = 'with-';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Lib\Security\PasswordGenerator $passwordGenerator
     * @param string $name
     * @param mixed $default
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Lib\Security\PasswordGenerator $passwordGenerator,
        private string $name = '',
        private mixed $default = null
    ) {
    }

    /**
     * @param string $name
     * @param mixed $default - used to have some value in case it is not passed via input
     * @return $this
     */
    public function initialize(string $name, mixed $default = null): UniversalReusableOption
    {
        return new UniversalReusableOption($this->composition, $this->passwordGenerator, $name, $default);
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

        $question = "<info>Parameter {{{$this->name}}} is required for the following services:</info>" . PHP_EOL;
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
                    $question .= "- <info>$serviceName</info> in file $file" . PHP_EOL;
                }
            }
        }

        if (!empty($parameterDefinedFor)) {
            $question .= PHP_EOL . 'Other services use the following value for this parameter:' . PHP_EOL;

            foreach ($parameterDefinedFor as $serviceName) {
                $service = $this->composition->getService($serviceName);
                $question .= "- <info>$serviceName</info>: {$service->getParameterValue($this->name)}" . PHP_EOL;
            }
        }

        // Definitely not a great way to handle this part here. A terrible way, but we need this to work now
        if (str_ends_with($this->name, '_random_password')) {
            $question .= 'Leave empty to auto-generate random value' . PHP_EOL;
        }

        $question .= '> ';

        return new Question($question);
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): mixed
    {
        // Definitely not a great way to handle this part here. A terrible way, but we need this to work now
        if (!$value && str_ends_with($this->name, '_random_password')) {
            $value = $this->passwordGenerator->generatePassword();
        }

        // User input is empty, but at least one service has the parameter value set
        if ($value === null && !$this->composition->isParameterMissed($this->name)) {
            return $this->composition->getParameterValue($this->name);
        }

        return $value;
    }
}
