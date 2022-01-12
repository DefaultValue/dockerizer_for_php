<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner as CommandOptionRunner;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'composition:build-from-template';

    // @TODO: add more options
    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME,
        CommandOptionRunner::OPTION_NAME
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
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
        // @TODO: Filesystem\Firewall to check current directory and protect from misuse!
        // Maybe ask for confirmation in such case, but still allow running inside the allowed directory(ies)

        $templateCode = $this->getOptionValueByOptionName(
            $input,
            $output,
            CommandOptionCompositionTemplate::OPTION_NAME
        );
        $template = $this->templateCollection->getByCode($templateCode);
        $this->composition->setTemplate($template);

        // @TODO: get packages from composer.json, ask for confirm if package version does not match supported versions
        // @TODO: Check if there is a `composer.json` here, suggest templates if possible
//        $template->getSupportedPackages();
//
//        if (file_exists('composer.json')) {
//
//        }

        // === Stage 1: Get all services we want to add to the composition ===
        // For now, services can't depend on other services. Thus, you need to create a service template that consists
        // of multiple services if required by the runner.
        $runnerCode = $this->getOptionValueByOptionName($input, $output, Runner::OPTION_NAME);
        $this->composition->addService($template->getRunnerByCode($runnerCode));

        // @TODO: get additional services
        $this->composition->addService($template->getPreconfiguredServiceByCode('mysql_5.6_persistent'))
            ->addService($template->getPreconfiguredServiceByCode('redis_5.0'))
            ->addService($template->getPreconfiguredServiceByCode('elasticsearch_6.8.11'));

        // @TODO: get parameters from all services, show which parameters does the following composition have
        $compositionParameters = $this->composition->getParameters();
        // $compositionParameters = ['domains', 'composer_version'];
        $preparedCompositionParameters = [];

        // === Stage 2: Populate services parameters ===
        foreach ($this->getCommandSpecificOptionDefinitions() as $option) {
            if (!in_array($option->getName(), $compositionParameters, true)) {
                continue;
            }

            $preparedCompositionParameters[$option->getName()] = $this->getOptionValue($input, $output, $option);
        }

        // === Stage 3: Ask to provide all missed options ===
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
