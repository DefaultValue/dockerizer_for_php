<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices
    as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner as CommandOptionRunner;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'composition:build-from-template';

    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME,
        CommandOptionRunner::OPTION_NAME,
        CommandOptionOptionalServices::OPTION_NAME
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
        $this->setDescription(<<<'TEXT'
            Create Docker composition from templates.
            Full command example:
            <fg=green>cd ~/misc/apps/my_awesome_project/
            php %command.full_name% --domains='google.com www.google.com' \
                --template="magento_2.4.4" \
                --runner="php_8.1_nginx" \
                --optional-services="redis_6.2,varnish_7.0"
        TEXT);
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

        // === Stage 1: Get all services we want to add to the composition ===
        // For now, services can't depend on other services. Thus, you need to create a service template that consists
        // of multiple services if required by the runner.
        $runnerName = $this->getOptionValueByOptionName($input, $output, Runner::OPTION_NAME);
        $this->composition->addService($template->getRunnerByName($runnerName));

        $optionalServices = $this->getOptionValueByOptionName(
            $input,
            $output,
            CommandOptionOptionalServices::OPTION_NAME
        );

        /** @var Service $service */
        foreach ($optionalServices as $serviceName) {
            $this->composition->addService($template->getPreconfiguredServiceByName($serviceName));
        }

        // @TODO: get parameters from all services, show which parameters does the following composition have
        // $compositionParameters = ['domains', 'composer_version'];
        $compositionParameters = $this->composition->getParameters();

        // === Stage 2: Populate services parameters ===
        foreach ($this->getCommandSpecificOptionNames() as $optionName) {
            if (!in_array($optionName, $compositionParameters, true)) {
                continue;
            }

            $this->composition->setServiceParameter(
                $optionName,
                $this->getOptionValueByOptionName($input, $output, $optionName)
            );
        }

        // === Stage 3: Ask to provide all missed options ===
        $this->populateMissedParameters();

        // @TODO: add --dry-run parameter to list all files and their content
        $this->dumpComposition($output, true);

        // @TODO: dump full command with all parameters


        // @TODO: connect runner with infrastructure if needed - add TraefikAdapter
        return self::SUCCESS;
    }

    /**
     * @param Template $template
     * @return Service[]
     */
    public function chooseOptionalServices(Template $template): array
    {


        return [];
    }





    private function populateMissedParameters()
    {
        // get missed parameters from composition (it gets them from services)
        // ask for every parameter, indicating which service it is required for
    }

    /**
     * @param OutputInterface $output
     * @param bool $write
     * @return void
     */
    private function dumpComposition(
        OutputInterface $output,
        bool $write = true
    ): void {
        foreach ($this->composition->dump($write) as $service => $files) {
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
