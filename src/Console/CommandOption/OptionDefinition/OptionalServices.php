<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Very simple way to ask for additional services, all groups at once.
 * Ideally, it would be better to ask for every group one by one to eliminate the case when multiple services from the
 * same group are selected
 */
class OptionalServices implements \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'optional-services';

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
        return InputOption::VALUE_OPTIONAL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List of optional services (comma-separated): --optional-service-redis=redis_5.0';
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
    public function getQuestion(): Question
    {
        $question =  new ChoiceQuestion(
            '<question>Choose optional services to use (comma-separated, one for every group):</question> ',
            $this->getServicesWithGroupInfo()
        );
        $question->setMultiselect(true);

        return $question;
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $value = array_unique($value);
        $servicesWithGroupInfo = $this->getServicesWithGroupInfo();
        $groupsWithSelectedService = [];

        foreach ($value as $serviceName) {
            if (in_array($servicesWithGroupInfo[$serviceName], $groupsWithSelectedService, true)) {
                throw new OptionValidationException('Must choose not more than one optional service from every group!');
            }

            $groupsWithSelectedService[] = $servicesWithGroupInfo[$serviceName];
        }

        return $value;
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
    private function getServicesWithGroupInfo(): array
    {
        $optionalServices = $this->composition->getTemplate()->getOptionalServices();
        $serviceWithGroupInfo = [];

        foreach ($optionalServices as $groupCode => $services) {
            foreach (array_keys($services) as $serviceName) {
                $serviceWithGroupInfo[$serviceName] = $groupCode;
            }
        }

        return $serviceWithGroupInfo;
    }
}
