<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Very simple way to ask for additional services, all groups at once.
 * Ideally, it would be better to ask for every group one by one to eliminate the case when multiple services from the
 * same group are selected
 */
class RequiredServices extends \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Service\AbstractService
{
    public const OPTION_NAME = 'required-services';

    public const SERVICE_TYPE = Service::TYPE_REQUIRED;

    /**
     * The value is optional because we actually can have 0 required services...
     *
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
        return 'List of required services (comma-separated): --required-service-mysql=mysql_8.0_persistent';
    }

    /**
     * @inheritDoc
     */
    public function getQuestion(): ?ChoiceQuestion
    {
        $this->prefillGroupsWithSingleSelection();

        if (!$this->getServicesForGroupsWithoutValue()) {
            return null;
        }

        return parent::getQuestion();
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): array
    {
        $this->prefillGroupsWithSingleSelection();
        $value = parent::validate($value);

        // Validate there are no groups without services
        if ($services = $this->getServicesForGroupsWithoutValue()) {
            throw new OptionValidationException(
                'Missed services for the following groups: ' . implode(', ', array_unique($services))
            );
        }

        return $value;
    }

    /**
     * For required services only! Do not ask to choose value in case there is only 1 selection available.
     * For required services this does not make sense
     * For optional services no selection means the service is not needed at all
     *
     * @return void
     */
    private function prefillGroupsWithSingleSelection(): void
    {
        $servicesByGroup = $this->composition->getTemplate()->getServices(static::SERVICE_TYPE);

        // If there is just one service in the group - preselect it automatically
        foreach ($servicesByGroup as $group => $services) {
            if (count($services) === 1) {
                $this->valueByGroup[$group] = array_keys($services)[0];
                // Output is not available, so skipping this. Do not want to add more dependencies here
                /*
                $this->output->writeln(sprintf(
                    "Preselecting required service <info>%s</info> for group <info>%s</info>...",
                    array_keys($services)[0],
                    $group
                ));
                */
            }
        }
    }
}
