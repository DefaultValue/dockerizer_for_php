<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\Command\Composition\BuildFromTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\UniversalReusableOption;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetUp extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    public const MAGENTO_CE_PACKAGE = 'magento/product-community-edition';

    protected static $defaultName = 'magento:setup';

    protected array $commandSpecificOptions = [
        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME,
        CommandOptionRequiredServices::OPTION_NAME,
        CommandOptionOptionalServices::OPTION_NAME,
        CommandOptionForce::OPTION_NAME
    ];

    /**
     * @param \Composer\Semver\VersionParser $versionParser
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param UniversalReusableOption $universalReusableOption
     * @param string|null $name
     */
    public function __construct(
        private \Composer\Semver\VersionParser $versionParser,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        iterable $commandArguments,
        iterable $availableCommandOptions,
        UniversalReusableOption $universalReusableOption,
        string $name = null
    ) {
        // Ignore validation error not to fail when unknown options are passed
        // Required for passing all options to the command `composition:build-from-template`
        $this->ignoreValidationErrors();
        parent::__construct($commandArguments, $availableCommandOptions, $universalReusableOption, $name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('magento:setup')
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command deploys clean Magento instance of the selected version.
                You can pass any additional options from `composition:build-from-template` to this command.
                Magento will be configured to use Varnish and Elasticsearch if they are present in the composition.
                Magento will not be configured to use Redis!

                Simple usage:

                    <info>php %command.full_name% 2.4.4</info>

                Install Magento with the pre-defined parameters:

                    <info>php %command.full_name% 2.4.4 -f \
                    --domains="magento-244-p81-nva.local www.magento-244-p81-nva.local" \
                    --template="magento_2.4.4_nginx_varnish_apache" \
                    --required-services="php_8_1_apache,mariadb_10_4_persistent,elasticsearch_7_16_3" \
                    --optional-services="redis_6_2"</info>

                Magento is configured to use the following services if available:
                - Varnish if any container containing `varnish` is available;
                - ElasticSearch if any container containing `elasticsearch` is available;

                Redis is not configured automatically!
                RabbitMQ: to be implemented. Your pull requests are appreciated!
                EOF)
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Semantic Magento version like 2.3.3-p1, 2.4.4 etc.'
            );

        parent::configure();
    }

    /**
     * @param ArgvInput $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Preset package info to get recommended templates if possible
        $magentoVersion = $input->getArgument('version');
        $this->versionParser->normalize($magentoVersion);

        $recommendedTemplates = $this->templateCollection->getRecommendedTemplates(
            self::MAGENTO_CE_PACKAGE,
            $magentoVersion
        );

        if (!count($recommendedTemplates)) {
            $output->writeln("<error>No recommended templates available for version $magentoVersion</error>");
        }

        /** @var CommandOptionCompositionTemplate $compositionTemplateOption */
        $compositionTemplateOption = $this->getCommandSpecificOption(CommandOptionCompositionTemplate::OPTION_NAME);
        $compositionTemplateOption->setPackage(self::MAGENTO_CE_PACKAGE, $magentoVersion);

        // Create working dir and chdir there. Shut down compositions in this directory if any
        $domains = $this->getOptionValueByOptionName($input, $output, CommandOptionDomains::OPTION_NAME);
        $domains = explode(OptionDefinitionInterface::VALUE_SEPARATOR, $domains);
        $projectRoot = $this->createProject->getProjectRoot($domains[0]);

        // Prepare composition files to run and install Magento inside
        // Proxy domains and other parameters so that the user is not asked the same question again
        // Do not dump composition - installer will do this when needed
        // @TODO: Choose first service from every available in case we're not in the interactive mode?
        $this->buildCompositionFromTemplate(
            $input,
            $output,
            [
                'command' => 'composition:build-from-template',
                '--' . CommandOptionDomains::OPTION_NAME => $domains,
                '--' . BuildFromTemplate::OPTION_PATH => $projectRoot,
                '--' . BuildFromTemplate::OPTION_DUMP => false
            ]
        );
        $force = $this->getOptionValueByOptionName($input, $output, CommandOptionForce::OPTION_NAME);

        // Install Magento
        try {
            $this->createProject->createProject($output, $magentoVersion, $domains, $force);
            $output->writeln('Docker container should be ready. Trying to install Magento...');
            $this->setupInstall->setupInstall(
                $output,
                $this->composition->getDockerComposeCollection($projectRoot)[0]
            );
            $output->writeln('Magento installation completed!');
        } catch (InstallationDirectoryNotEmptyException | CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->writeln("<error>An error appeared during installation: {$e->getMessage()}</error>");
            $output->writeln('Cleaning up the project composition and files...');
            // @TODO: cleanup on CTRL+C, see \DefaultValue\Dockerizer\Process\Multithread or register_shutdown_function
            $this->createProject->cleanUp($projectRoot);
            throw $e;
        }

        return self::SUCCESS;
    }

    /**
     * Use the command `composition:build-from-template` to prepare composition without dumping it
     *
     * @param ArgvInput|ArrayInput $input
     * @param OutputInterface $output
     * @param array $additionalOptions
     * @return void
     * @throws \Exception
     */
    private function buildCompositionFromTemplate(
        ArgvInput|ArrayInput $input,
        OutputInterface $output,
        array $additionalOptions
    ): void {
        if (!$this->getApplication()) {
            // Just not to have a `Null pointer exception may occur here`
            throw new \RuntimeException('Application initialization failure');
        }

        // Proxy all input and output, because we have variable amount of parameters and all of them must be passed
        // to the command `composition:build-from-template`
        $command = $this->getApplication()->find($additionalOptions['command']);

        if ($command->run($this->buildInput($input, $additionalOptions), $output)) {
            throw new \RuntimeException('Can\'t build composition for the project');
        }
    }

    /**
     * @param ArgvInput|ArrayInput $input
     * @param array $additionalOptions
     * @return ArrayInput
     */
    private function buildInput(
        ArgvInput|ArrayInput $input,
        array $additionalOptions = []
    ): ArrayInput {
        // ArgvInput|ArrayInput have `__toString` method, allowing to collect and proxy options to another command
        // Not yet tested with `ArrayInput`!
        $inputArray = [];
        $collect = false;
        $optionName = '';

        // Collect all options incl. the ones that have multiple values
        // Skip all argument independently of their position
        foreach (explode(' ', (string) $input) as $option) {
            // option start
            if (str_starts_with($option, '-')) {
                [$optionName, $optionValue] = explode('=', $option);
                $inputArray[$optionName] = is_string($optionValue) ? trim($optionValue, '\'') : $optionValue;

                if (
                    is_string($optionValue)
                    && str_starts_with($optionValue, '\'')
                    && !str_ends_with($optionValue, '\'')
                ) {
                    $collect = true;

                    continue;
                }
            } else {
                $optionValue = $option;
            }

            if ($collect) {
                $inputArray[$optionName] .= ' ' . $optionValue;

                if (str_ends_with($optionValue, '\'')) {
                    $collect = false;
                    $inputArray[$optionName] = trim($inputArray[$optionName], '\'');
                }
            }
        }

        foreach ($additionalOptions as $optionName => $value) {
            $inputArray[$optionName] = $value;
        }

        uksort($inputArray, static function ($a) {
            return str_starts_with($a, '--with-');
        });

        return new ArrayInput($inputArray);
    }
}
