<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Console\CommandOption\Domains as CommandOptionDomains;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'composition:build-from-template';

    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME
    ];

    // add domainValidator, validate domains
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateCollection $templateCollection,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\ServiceCollection $serviceCollection,
        iterable $commandArguments,
        iterable $commandOptions,
        string $name = null
    ) {
        parent::__construct($commandArguments, $commandOptions, $name);
    }

    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates in `./templates/apps/`');

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @TODO: validate all recipes?
        // @TODO: Move hardcoded value to parameters
        // @TODO: get packages from composer.json, ask for confirm if package version does not match supported versions
        // @TODO: Check if there is a `composer.json` here, suggers templates if possible
        $template = $this->templateCollection->getTemplate('magento_2.0.2-2.0.x.yaml');

        // Stage 1: Get all services we want to add to the composition
        // For now, services can't depend on other services. Thus, you need to create a service template that consists
        // of multiple services if required by the runner.
        // @TODO: select which required and optional services to use
        $this->composition->addService($this->serviceCollection->getService('php_5.6_apache.yaml'))
            ->addService($this->serviceCollection->getService('mysql_5.6_persistent.yaml'))
            ->addService($this->serviceCollection->getService('redis_5.0.yaml'))
            ->addService($this->serviceCollection->getService('elasticsearch_6.8.11.yaml'));

        // Stage 2: Populate services with options
        foreach ($this->getCommandOptions() as $option) {
            $value = $this->getOptionValue($input, $output, $option);
            $foo = false;
        }


        return self::FAILURE;

        // Stage 3: Ask to provide all missed options
        foreach ($composition->getMissedParameters() as $parameter) {

        }

        $composition->getFiles();


        // @TODO: add --dry-run parateter to list all files and their content
        // instead of creating them or rewriting anything
        $output->writeln($composition->dump(true));


        return self::SUCCESS;
    }
}
