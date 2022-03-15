<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices
    as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices
    as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner as CommandOptionRunner;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\UniversalReusableOption;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @noinspection PhpUnused */
class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    public const OPTION_PATH = 'path';

    public const OPTION_DUMP = 'dump';

    protected static $defaultName = 'composition:build-from-template';

    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME,
        CommandOptionRunner::OPTION_NAME,
        CommandOptionRequiredServices::OPTION_NAME,
        CommandOptionOptionalServices::OPTION_NAME,
        CommandOptionForce::OPTION_NAME
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param UniversalReusableOption $universalReusableOption
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private UniversalReusableOption $universalReusableOption,
        iterable $commandArguments,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        // Ignore validation error not to fail when unknown options are passed
        // Required for handling variable number of options via UniversalReusableOption
        $this->ignoreValidationErrors();
        parent::__construct($commandArguments, $availableCommandOptions, $universalReusableOption, $name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates')
        ->setHelp(<<<'TEXT'
            Full command example:
            <fg=green>cd ~/misc/apps/my_awesome_project/
            php %command.full_name% \
                --template="magento_2.1_nginx_varnish_apache" \
                --domains='test-varnish.local www.test-varnish.local' \
                --required-services="php_7.0_apache,mysql_5.7_persistent" \
                --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
                --with-environment='dev' \
                --with-web_root="app/"
            Additional options (if any) will be asked during the command run. 
            TEXT)
        ->addOption(
            self::OPTION_PATH,
            null,
            InputOption::VALUE_OPTIONAL,
            'Project root path (current folder if not specified). Mostly for internal use by the `magento:setup`.'
        )
        ->addOption(
            self::OPTION_DUMP,
            null,
            InputOption::VALUE_OPTIONAL,
            'Dump composition files.',
            true
        );
        // @TODO: add --autoselect option to automatically choose services in case of non-interactive mode. Or do this wihotut an optoin
        // @TODO: add `--options` option to show options for selected services without building the composition?
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($projectRoot = trim((string) $input->getOption(self::OPTION_PATH))) {
            $projectRoot = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR;
            chdir($projectRoot);
        }

        // Used later to dump composition, but defined here to keep the variable definition
        // near the place were chdir() happens
        $projectRoot = getcwd() . DIRECTORY_SEPARATOR;

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
        $runnerName = $this->getOptionValueByOptionName($input, $output, CommandOptionRunner::OPTION_NAME);
        $this->composition->addService($runnerName);

        $addServices = function ($optionName) use ($input, $output) {
            $services = $this->getOptionValueByOptionName($input, $output, $optionName);

            /** @var Service $service */
            foreach ($services as $serviceName) {
                $this->composition->addService($serviceName);
            }
        };

        $addServices(CommandOptionRequiredServices::OPTION_NAME);
        $addServices(CommandOptionOptionalServices::OPTION_NAME);

        // === Stage 2: Populate services parameters ===
        $compositionParameters = $this->composition->getParameters();

        foreach ($this->getCommandSpecificOptionNames() as $optionName) {
            if (!in_array($optionName, $compositionParameters['missed'], true)) {
                continue;
            }

            $this->composition->setServiceParameter(
                $optionName,
                $this->getOptionValueByOptionName($input, $output, $optionName)
            );
        }

        // Must unset variable, because missed parameters list has changed after asking for required options
        unset($compositionParameters);

        // === Stage 3: Ask to provide all missed options ===
        $parametersToSkip = array_merge(
            $this->commandSpecificOptions,
            [self::OPTION_PATH] // yes, it is not fine to hardcode a list of command options
        );
        $allParameters = array_filter(
            $this->composition->getParameters()['all'],
            static fn ($value) => !in_array($value, $parametersToSkip, true)
        );

        // Can bind input only once. Must check if it is possible to change this and extract adding options
        // to `getUniversalReusableOptionValue`. Otherwise can call `$input->bind($this->getDefinition());` just once
        // Add all universal options, not just missed ones. Otherwise, we get `The "--with-foo" option does not exist.`
        foreach ($allParameters as $missedParameter) {
            $optionDefinition = $this->universalReusableOption->setName($missedParameter);
            $this->addOption(
                $optionDefinition->getName(),
                $optionDefinition->getShortcut(),
                $optionDefinition->getMode(),
                $optionDefinition->getDescription(),
                $optionDefinition->getDefault()
            );
        }

        $input->bind($this->getDefinition());

        // Ask only for missed parameters
        foreach ($this->composition->getParameters()['missed'] as $missedParameter) {
            $this->composition->setServiceParameter(
                $missedParameter,
                $this->getUniversalReusableOptionValue($input, $output, $missedParameter)
            );
        }

        // === Stage 4: Dump composition ===
        // @TODO: add --dry-run option to list all files and their content
        if ($input->getOption(self::OPTION_DUMP)) {
            $this->composition->dump(
                $output,
                $projectRoot,
                $this->getOptionValueByOptionName($input, $output, CommandOptionForce::OPTION_NAME)
            );
        }

        // Dump the whole command to copy-paste and reuse it will all parameters
        $output->writeln((string) $input);

        // @TODO: connect runner with infrastructure if needed - add TraefikAdapter
        return self::SUCCESS;
    }
}
