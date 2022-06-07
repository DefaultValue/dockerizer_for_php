<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition;

use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Choose from the available project compositions if multiple are configured (may not be running though)
 */
class Composition implements
    \DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface,
    \DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface
{
    public const OPTION_NAME = 'composition';

    public const ARGUMENT_COLLECTION_FILTER = 'collection-filter';

    private string $filter;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
    ) {
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
        return 'Composition name within this project';
    }

    /**
     * @return null
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @param string $filter
     * @return void
     */
    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    /**
     * @return ChoiceQuestion|null
     */
    public function getQuestion(): ?ChoiceQuestion
    {
        $dockerComposeCollection = $this->getDockerComposeCollection();

        if (!count($dockerComposeCollection)) {
            throw new \InvalidArgumentException(
                'No compositions found in '
                . $this->composition->getDockerizerDirInProject(getcwd() . DIRECTORY_SEPARATOR)
            );
        }

        if (count($dockerComposeCollection) === 1) {
            return null;
        }

        return new ChoiceQuestion(
            'Choose composition:',
            array_keys($dockerComposeCollection),
            array_keys($dockerComposeCollection)[0]
        );
    }

    /**
     * @inheritDoc
     */
    public function validate(mixed $value): string
    {
        $dockerComposeCollection = $this->getDockerComposeCollection();

        if ($value === null && count($dockerComposeCollection) === 1) {
            $value = array_keys($dockerComposeCollection)[0];
        }

        if (!array_key_exists($value, $dockerComposeCollection)) {
            throw new OptionValidationException("Not a valid composition name: $value");
        }

        return $value;
    }

    /**
     * @return array
     */
    private function getDockerComposeCollection(): array
    {
        return $this->composition->getDockerComposeCollection(
            getcwd() . DIRECTORY_SEPARATOR,
            $this->filter
        );
    }
}
