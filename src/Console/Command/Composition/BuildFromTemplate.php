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
use Symfony\Component\Yaml\Yaml;

/** @noinspection PhpUnused */
class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    public const OPTION_PATH = 'path';

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
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private UniversalReusableOption $universalReusableOption,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
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
        $this->setDescription(<<<'TEXT'
            Create Docker composition from templates.
            Full command example:
            <fg=green>cd ~/misc/apps/my_awesome_project/
            php %command.full_name% --domains='google.com www.google.com' \
                --template="magento_2.4.4" \
                --runner="php_8.1_nginx" \
                --optional-services="redis_6.2,varnish_7.0"
        TEXT)
        ->addOption(
            self::OPTION_PATH,
            null,
            InputOption::VALUE_OPTIONAL,
            'Project root path (current folder if not specified). Mostly for internal use by the `magento:setup`.'
        );
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
            // Used later in `$this::compileAndDump()`
            chdir($projectRoot);
        }

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
        // @TODO: add --dry-run parameter to list all files and their content
        $this->compileAndDump($input, $output);

        // @TODO: connect runner with infrastructure if needed - add TraefikAdapter
        return self::SUCCESS;
    }

    /**
     * @TODO: Maybe should move this to some external service. Will leave here for now because YAGNI
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    public function compileAndDump(InputInterface $input, OutputInterface $output): void
    {
        // @TODO: dump full command with all parameters here, as we may exit while dumping the composition
        // get php binary + executed file + command name + all parameters (and escape everything?....)

        $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
        $runnerYaml = Yaml::parse($this->composition->getRunner()->compileServiceFile());
        $mainService = array_keys($runnerYaml['services'])[0];
        $mainContainerName = $runnerYaml['services'][$mainService]['container_name'];
        $dumpTo = $projectRoot . '.dockerizer' . DIRECTORY_SEPARATOR . $mainContainerName;

        $this->prepareDirectoryToDumpComposition(
            $output,
            $dumpTo,
            (bool) $this->getOptionValueByOptionName($input, $output, CommandOptionForce::OPTION_NAME)
        );
        $dumpTo .= DIRECTORY_SEPARATOR;

        // 1. Dump main file
        $compositionYaml = [$runnerYaml];
        $mountedFiles = [$this->composition->getRunner()->compileMountedFiles()];

        foreach ($this->composition->getAdditionalServices() as $service) {
            $compositionYaml[] = Yaml::parse($service->compileServiceFile());
            // Yes, the same service can be added several times with different files
            $mountedFiles[] = $service->compileMountedFiles();
        }

        $compositionYaml = array_replace_recursive(...$compositionYaml);
        $compositionYaml['version'] = $runnerYaml['version'];
        $this->filesystem->dumpFile($dumpTo . 'docker-compose.yaml', Yaml::dump($compositionYaml, 32, 2));

        // 2. Dump dev tools
        if ($devTools = $this->composition->getDevTools()) {
            $this->filesystem->dumpFile($dumpTo . 'docker-compose-dev-tools.yaml', $devTools->compileServiceFile());
            $mountedFiles[] = $devTools->compileMountedFiles();
        }

        // 3. Dump all mounted files
        $mountedFiles = array_unique(array_merge(...$mountedFiles));

        foreach ($mountedFiles as $relativeFileName => $mountedFileContent) {
            $this->filesystem->dumpFile($dumpTo . $relativeFileName, $mountedFileContent);
        }
    }

    /**
     * @param string $dumpTo
     * @param bool $force
     * @return void
     */
    private function prepareDirectoryToDumpComposition(OutputInterface $output, string $dumpTo, bool $force): void
    {
        // If the path already exists - try stopping any composition(s) defined there
        if ($this->filesystem->exists($dumpTo)) {
            if ($force) {
                if (is_dir($dumpTo)) {
                    $output->writeln("<comment>Shutting down compositions (if any) in: $dumpTo</comment>");
                    $this->dockerCompose->setCwd($dumpTo)->down();
                }

                $this->filesystem->remove($dumpTo);
            } else {
                throw new \RuntimeException(
                    "Directory $dumpTo already exists and is ton empty. Add `-f` to force override its content."
                );
            }
        }

        $this->filesystem->mkdir($dumpTo);
    }
}
