<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Service;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

abstract class AbstractService implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    // Redefine in the child class
    public const OPTION_NAME = '';

    // Redefine in the child class: either Service::TYPE_REQUIRED or Service::TYPE_OPTIONAL
    public const SERVICE_TYPE = '';

    protected array $groupsWithSelectedService = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     */
    public function __construct(private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition)
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
     * @inheritDoc
     */
    public function getQuestion(): ?Question
    {
        if (!($services = $this->getServicesWithGroupInfo())) {
            return null;
        }

        $optionName = static::OPTION_NAME;
        $question = new ChoiceQuestion(
            "<question>$optionName: choose services to use (comma-separated, one for every group):</question>",
            $this->getServicesForUnselectedGroups($services)
        );
        $question->setMultiselect(true);

        return $question;
    }

    /**
     * [
     *     'foo' => 'redis',
     *     'bar' => 'redis',
     *     'baz' => 'varinsh'
     * ]
     *
     * @return array
     */
    protected function getServicesWithGroupInfo(): array
    {
        $optionalServices = $this->composition->getTemplate()->getServices(static::SERVICE_TYPE);
        $serviceWithGroupInfo = [];

        foreach ($optionalServices as $groupCode => $services) {
            foreach (array_keys($services) as $serviceName) {
                $serviceWithGroupInfo[$serviceName] = $groupCode;
            }
        }

        return $serviceWithGroupInfo;
    }

    /**
     * Filter services by group, if selection for that group has been provided
     *
     * @param array $services
     * @return array
     */
    protected function getServicesForUnselectedGroups(array $services): array
    {
        return array_filter($services, fn ($value) => !in_array($value, $this->groupsWithSelectedService, true));
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

        $value = array_unique($value);
        $servicesWithGroupInfo = $this->getServicesWithGroupInfo();

        foreach ($value as $serviceName) {
            if (!isset($servicesWithGroupInfo[$serviceName])) {
                throw new \InvalidArgumentException(
                    "Service does not belong to any group in this template: $serviceName"
                );
            }

            if (in_array($servicesWithGroupInfo[$serviceName], $this->groupsWithSelectedService, true)) {
                throw new OptionValidationException('Must choose not more than one optional service from every group!');
            }

            $this->groupsWithSelectedService[] = $servicesWithGroupInfo[$serviceName];
        }

        return $value;
    }
}
