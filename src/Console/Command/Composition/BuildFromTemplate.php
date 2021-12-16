<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'composition:build-from-template';

    // @TODO: add more options
    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Collection $serviceCollection
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Collection $serviceCollection,
        iterable $commandArguments,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($commandArguments, $availableCommandOptions, $name);
    }

    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates in `./templates/apps/`');
        // @TODO: add `--options` option to show options for selected services without building the composition?
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @TODO: validate all recipes?
        // @TODO: Move hardcoded value to parameters
        // @TODO: get packages from composer.json, ask for confirm if package version does not match supported versions
        // @TODO: Check if there is a `composer.json` here, suggers templates if possible
        $templateCode = $this->getOptionValueByName(
            $input,
            $output,
            CommandOptionCompositionTemplate::OPTION_NAME
        );
        $template = $this->templateCollection->getFile($templateCode);

        // Stage 1: Get all services we want to add to the composition
        // For now, services can't depend on other services. Thus, you need to create a service template that consists
        // of multiple services if required by the runner.
        // @TODO: select which required and optional services to use
        // @TODO: this must be an option, so that service list can be provided without additional interaction
        $this->composition->addService($this->serviceCollection->getFile('php_5.6_apache'), true)
            ->addService($this->serviceCollection->getFile('mysql_5.6_persistent'))
            ->addService($this->serviceCollection->getFile('redis_5.0'))
            ->addService($this->serviceCollection->getFile('elasticsearch_6.8.11'));

        // @TODO: get parameters from all services, show which parameters does the following composition have
        // $compositionParameters = $this->composition->getParameters();
        $compositionParameters = ['domains', 'composer_version'];
        $preparedCompositionParameters = [];

        // Stage 2: Populate services parameters
        foreach ($this->getCommandSpecificOptionDefinitions() as $option) {
            if (!in_array($option->getName(), $compositionParameters, true)) {
                continue;
            }

            $preparedCompositionParameters[$option->getName()] = $this->getOptionValue($input, $output, $option);
        }

        // Stage 3: Ask to provide all missed options
        $this->populateMissedParameters($compositionParameters, $preparedCompositionParameters);

        // @TODO: add --dry-run parameter to list all files and their content
        $this->dumpComposition($output, $preparedCompositionParameters, true);

        // @TODO: dump full command with all parameters


        // @TODO: connect runner with infrastructure if needed - add TraefikAdapter
        return self::SUCCESS;
    }

    private function populateMissedParameters(array $compositionParameters, array &$preparedCompositionParameters)
    {

    }

    /**
     * @param OutputInterface $output
     * @param array $preparedCompositionParameters
     * @param bool $write
     * @return void
     */
    private function dumpComposition(
        OutputInterface $output,
        array $preparedCompositionParameters,
        bool $write = true
    ): void {
        foreach ($this->composition->dump($preparedCompositionParameters, $write) as $service => $files) {
            $output->writeln("Service: <info>$service</info>");

            foreach ($files as $file => $content) {
                $output->writeln("Service template: <info>$file</info>");
                $output->writeln("<info>$content</info>");
                $output->writeln('');
            }

            $output->writeln('');
        }
    }
}
