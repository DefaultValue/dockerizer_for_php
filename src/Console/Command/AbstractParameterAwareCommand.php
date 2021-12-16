<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\InteractiveOptionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\ValidatableOptionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\ValidationException as OptionValidationException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractParameterAwareCommand extends \Symfony\Component\Console\Command\Command
{
    private const DEFAULT_RETRIES = 3;

    protected array $commandSpecificArguments = [];

    /**
     * Command specific option names. Use names to avoid adding options with identical names.
     *
     * @var string[] $commandSpecificOptions
     */
    protected array $commandSpecificOptions = [];

    /**
     * @var OptionDefinitionInterface[]
     */
    private array $commandSpecificOptionDefinitions = [];

    /**
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private iterable $commandArguments,
        private iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        parent::configure();

        $commandSpecificOptionDefinitions = [];

        /** @var OptionDefinitionInterface $optionDefinition */
        foreach ($this->availableCommandOptions as $optionDefinition) {
            if (in_array($optionDefinition->getName(), $this->commandSpecificOptions, true)) {
                $commandSpecificOptionDefinitions[$optionDefinition->getName()] = $optionDefinition;
                $this->addOption(
                    $optionDefinition->getName(),
                    $optionDefinition->getShortcut(),
                    $optionDefinition->getMode(),
                    $optionDefinition->getDescription(),
                    $optionDefinition->getDefault()
                );
            }
        }

        if ($unknownOptions = array_diff($this->commandSpecificOptions, array_keys($commandSpecificOptionDefinitions))) {
            throw new \RuntimeException('Unknown command option(s): ' . implode(', ', $unknownOptions));
        }

        $this->commandSpecificOptionDefinitions = $commandSpecificOptionDefinitions;
    }

    /**
     * @return OptionDefinitionInterface[]
     */
    protected function getCommandSpecificOptionDefinitions(): array
    {
        return $this->commandSpecificOptionDefinitions;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param OptionDefinitionInterface $optionDefinition
     * @param int $retries - retries left if value validation failed
     * @param array $arguments
     * @return mixed
     */
    protected function getOptionValue(
        InputInterface $input,
        OutputInterface $output,
        OptionDefinitionInterface $optionDefinition,
        int $retries = self::DEFAULT_RETRIES,
        ...$arguments
    ): mixed {
        if (!$retries) {
            throw new \RuntimeException(
                "Too many retries to enter the valid value for '{$optionDefinition->getName()}'. Exiting.."
            );
        }

        if (!($value = $input->getOption($optionDefinition->getName()))) {
            $optionType = $optionDefinition->getMode() === InputOption::VALUE_REQUIRED ? 'mandatory' : 'optional';
            $output->writeln(
                "Missed <info>$optionType</info> value for option <info>{$optionDefinition->getName()}</info>"
            );
        }

        // No required value in the non-interactive mode -> exception
        if (
            !$value
            && $optionDefinition->getMode() === InputOption::VALUE_REQUIRED
            && !$input->isInteractive()
        ) {
            throw new \RuntimeException(
                "Required option '{$optionDefinition->getName()}' does not have value in the non-interactive mode"
            );
        }

        // No value passed in the input
        if (
            !$value
            && $optionDefinition instanceof InteractiveOptionInterface
            && $input->isInteractive()
        ) {
            $value = $optionDefinition->ask($input, $output, $this->getHelper('question'), ...$arguments);
        }

        // Still no value passed for the required option
        if (
            !$value
            && $optionDefinition->getMode() === InputOption::VALUE_REQUIRED
            && $input->isInteractive()
        ) {
            // Reset option to be able to ask for it again
            $input->setOption($optionDefinition->getName(), null);

            return $this->getOptionValue($input, $output, $optionDefinition, --$retries, ...$arguments);
        }

        if ($optionDefinition instanceof ValidatableOptionInterface) {
            try {
                $optionDefinition->validate($value);
            } catch (OptionValidationException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");

                if ($input->isInteractive()) {
                    // Reset option to be able to ask for it again
                    $input->setOption($optionDefinition->getName(), null);

                    return $this->getOptionValue($input, $output, $optionDefinition, --$retries, ...$arguments);
                }

                $output->writeln("<error>Can't proceed in the non-interactive mode! Exiting...</error>");
            }
        }

        return $value;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $optionName
     * @param array $arguments
     * @return mixed
     */
    protected function getOptionValueByName(
        InputInterface $input,
        OutputInterface $output,
        string $optionName,
        ...$arguments
    ): mixed {
        return $this->getOptionValue(
            $input,
            $output,
            $this->commandSpecificOptionDefinitions[$optionName],
            self::DEFAULT_RETRIES,
            ...$arguments
        );
    }
}
