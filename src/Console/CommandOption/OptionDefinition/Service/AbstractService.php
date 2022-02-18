<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Service;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Question\ChoiceQuestion;

abstract class AbstractService implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    // Redefine in the child class
    public const OPTION_NAME = '';

    // Redefine in the child class: either Service::TYPE_REQUIRED or Service::TYPE_OPTIONAL
    public const SERVICE_TYPE = '';

    /**
     * Store information about which groups already have a valid value
     *
     * @var array $valueByGroup
     */
    protected array $valueByGroup = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     */
    public function __construct(protected \DefaultValue\Dockerizer\Docker\Compose\Composition $composition)
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return static::OPTION_NAME;
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
    abstract public function getMode(): int;

    /**
     * @inheritDoc
     */
    abstract public function getDescription(): string;

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @return ?ChoiceQuestion
     */
    public function getQuestion(): ?ChoiceQuestion
    {
        if (!$this->getServicesWithGroupInfo()) {
            return null;
        }

        $optionName = static::OPTION_NAME;
        $question = new ChoiceQuestion(
            "<info>$optionName: choose services to use (comma-separated, one for every group):</info>",
            $this->getServicesForGroupsWithoutValue()
        );
        $question->setMultiselect(true);

        return $question;
    }

    /**
     * Validate that no services from the same group are passed
     *
     * @inheritDoc
     */
    public function validate(mixed $value): array
    {
        if ($value === null) {
            $value = [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $value = array_map('trim', array_unique($value));
        $servicesWithGroupInfo = $this->getServicesWithGroupInfo();
        $groupsWithError = [];

        foreach ($value as $serviceName) {
            if (!isset($servicesWithGroupInfo[$serviceName])) {
                throw new \InvalidArgumentException(
                    "Service does not belong to any group in this template: $serviceName"
                );
            }

            $groupName = $servicesWithGroupInfo[$serviceName];

            if (in_array($groupName, $groupsWithError, true)) {
                continue;
            }

            if (isset($this->valueByGroup[$groupName]) && $this->valueByGroup[$groupName] !== $serviceName) {
                unset($this->valueByGroup[$groupName]);
                $groupsWithError[] = $groupName;

                continue;
            }

            $this->valueByGroup[$groupName] = $serviceName;
        }

        if ($groupsWithError) {
            throw new OptionValidationException('Must choose not more than one optional service from every group!');
        }

        return array_values($this->valueByGroup);
    }

    /**
     * [
     *     'redis_variant_1' => 'redis',
     *     'redis_variant_2' => 'redis',
     *     'varnish_service' => 'varnish'
     * ]
     *
     * @return array
     */
    private function getServicesWithGroupInfo(): array
    {
        $servicesByGroup = $this->composition->getTemplate()->getServices(static::SERVICE_TYPE);
        $serviceWithGroupInfo = [];

        foreach ($servicesByGroup as $group => $services) {
            foreach (array_keys($services) as $serviceName) {
                $serviceWithGroupInfo[$serviceName] = $group;
            }
        }

        return $serviceWithGroupInfo;
    }

    /**
     * Filter services by group, if selection for that group has been provided
     *
     * @return array
     */
    protected function getServicesForGroupsWithoutValue(): array
    {
        return array_filter(
            $this->getServicesWithGroupInfo(),
            fn ($value) => !isset($this->valueByGroup[$value])
        );
    }
}
