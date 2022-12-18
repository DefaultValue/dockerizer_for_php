<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Composition as CommandOptionComposition;
use DefaultValue\Dockerizer\Docker\Compose;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commands that require user to choose from `.dockerizer/./` compositions if multiple available
 */
abstract class AbstractCompositionAwareCommand extends AbstractParameterAwareCommand
{
    protected array $commandSpecificOptions = [
        CommandOptionComposition::OPTION_NAME,
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface[] $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Compose
     */
    protected function selectComposition(InputInterface $input, OutputInterface $output): Compose
    {
        if ($input->hasArgument(CommandOptionComposition::ARGUMENT_COLLECTION_FILTER)) {
            $filter = (string) $input->getArgument(CommandOptionComposition::ARGUMENT_COLLECTION_FILTER);
        } else {
            $filter = '';
        }

        /** @var CommandOptionComposition $commandOptionComposition */
        $commandOptionComposition = $this->getCommandSpecificOption(CommandOptionComposition::OPTION_NAME);
        $commandOptionComposition->setFilter($filter);
        $dockerCompose = $this->getCommandSpecificOptionValue($input, $output, CommandOptionComposition::OPTION_NAME);
        $collection = $this->compositionCollection->getList('', (string) $dockerCompose);

        return $collection[$dockerCompose];
    }
}
