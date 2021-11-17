<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command;

use DefaultValue\Dockerizer\Console\CommandOption\CommandOptionDefinitionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\InteractiveCommandOptionDefinitionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractParameterAwareCommand extends \Symfony\Component\Console\Command\Command
{
    protected array $commandSpecificArguments = [];

    protected array $commandSpecificOptions = [];

    /**
     * @var CommandOptionDefinitionInterface[]
     */
    private array $commandSpecificOptionObjets = [];

    /**
     * @param iterable $commandArguments
     * @param iterable $commandOptions
     * @param string|null $name
     */
    public function __construct(
        private iterable $commandArguments,
        private iterable $commandOptions,
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

        $commandSpecificOptions = [];

        /** @var CommandOptionDefinitionInterface $optionDefinition */
        foreach ($this->commandOptions as $optionDefinition) {
            if (in_array($optionDefinition->getName(), $this->commandSpecificOptions, true)) {
                $commandSpecificOptions[$optionDefinition->getName()] = $optionDefinition;
                $this->addOption(
                    $optionDefinition->getName(),
                    $optionDefinition->getShortcut(),
                    $optionDefinition->getMode(),
                    $optionDefinition->getDescription(),
                    $optionDefinition->getDefault()
                );
            }
        }

        if ($unknownOptions = array_diff($this->commandSpecificOptions, array_keys($commandSpecificOptions))) {
            throw new \RuntimeException('Unknown command option(s): ' . implode(', ', $unknownOptions));
        }

        $this->commandSpecificOptionObjets = $commandSpecificOptions;
    }

    /**
     * @return CommandOptionDefinitionInterface[]
     */
    protected function getCommandOptions(): array
    {
        return $this->commandSpecificOptionObjets;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param CommandOptionDefinitionInterface $optionDefinition
     * @param array $arguments
     * @return mixed
     */
    protected function getOptionValue(
        InputInterface $input,
        OutputInterface $output,
        CommandOptionDefinitionInterface $optionDefinition,
        ...$arguments
    ) {
        if (!($value = $input->getOption($optionDefinition->getName()))
            && $optionDefinition->getMode() === InputOption::VALUE_REQUIRED
            && $optionDefinition instanceof InteractiveCommandOptionDefinitionInterface
        ) {
            $value = $optionDefinition->ask($input, $output, $this->getHelper('question'), ...$arguments);
        }

        if ($optionDefinition instanceof ValidatableOptionInterface) {
            $optionDefinition->validate($value);
        }

        // @TODO: ask questions, check for silent mode and so on
        return $value;
    }
}
