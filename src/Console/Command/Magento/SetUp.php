<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\Command\Composition\BuildFromTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;

class SetUp extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    public const MAGENTO_CE_PACKAGE = 'magento/product-community-edition';

    public const INPUT_ARGUMENT_MAGENTO_VERSION = 'magento-version';

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
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \Composer\Semver\VersionParser $versionParser,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        // Ignore validation error not to fail when unknown options are passed
        // Required for passing all options to the command `composition:build-from-template`
        $this->ignoreValidationErrors();
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('Generate Docker composition from the selected template and install Magento')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command deploys clean Magento instance of the selected version.
                You can pass any additional options from `composition:build-from-template` to this command.
                Magento will be configured to use Varnish and Elasticsearch if they are present in the composition.
                Magento will not be configured to use Redis!

                Simple usage:

                    <info>php %command.full_name% 2.4.4</info>

                Install Magento with the pre-defined parameters:

                    <info>php %command.full_name% 2.4.4 -f \
                    --domains='my-magento-project.local www.my-magento-project.local' \
                    --template=magento_2.4.4_nginx_varnish_apache \
                    --required-services='php_8_1_apache,mariadb_10_4_persistent,elasticsearch_7_16_3' \
                    --optional-services=redis_6_2</info>

                Magento is configured to use the following services if available:
                - Varnish if any container containing `varnish` is available;
                - ElasticSearch if any container containing `elasticsearch` is available;

                Redis is not configured automatically!
                RabbitMQ: to be implemented. Your pull requests are appreciated!
                EOF)
            ->addArgument(
                self::INPUT_ARGUMENT_MAGENTO_VERSION,
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Preset package info to get recommended templates if possible
        $magentoVersion = $input->getArgument(self::INPUT_ARGUMENT_MAGENTO_VERSION);
        $this->versionParser->normalize($magentoVersion);

        // Check we can install Magento in the selected directory
        $domains = $this->getCommandSpecificOptionValue($input, $output, CommandOptionDomains::OPTION_NAME);
        $domains = explode(OptionDefinitionInterface::VALUE_SEPARATOR, $domains);
        $projectRoot = $this->createProject->getProjectRoot($domains[0]);
        $force = $this->getCommandSpecificOptionValue($input, $output, CommandOptionForce::OPTION_NAME);
        // @TODO: add the ability to provide project root instead of using a domain name?
        $this->createProject->validateCanInstallHere($output, $projectRoot, $force);

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

        // Prepare composition files to run and install Magento inside
        // Proxy domains and other parameters so that the user is not asked the same question again
        // Do not dump composition - installer will do this when needed
        $this->buildCompositionFromTemplate(
            $input,
            $output,
            [
                'command' => 'composition:build-from-template',
                '--' . CommandOptionDomains::OPTION_NAME => $domains,
                '--' . BuildFromTemplate::OPTION_PATH => $projectRoot,
                '--' . BuildFromTemplate::OPTION_NO_DUMP => null
            ]
        );

        // Install Magento
        try {
            // Handle CTRL+C
            $signalRegistry = $this->getApplication()?->getSignalRegistry()
                ?? throw new \LogicException('Application is not initialized');
            $signalRegistry->register(
                SIGINT,
                function () use ($output, $projectRoot) {
                    $output->writeln(
                        '<error>Process interrupted. Cleaning up the project. Please, wait...</error>'
                    );
                    $this->createProject->cleanup($projectRoot);
                    $output->writeln('<info>Cleanup completed!</info>');

                    exit(self::SUCCESS);
                }
            );

            $output->writeln('Docker container should be ready. Trying to create and configure a composer project...');
            $this->createProject->createProject($output, $magentoVersion, $domains, $force);
            $output->writeln('Setting up Magento...');
            // CWD is changed while creating project, so setup happens in the project root dir
            $this->setupInstall->setupInstall(
                $output,
                array_values($this->compositionCollection->getList($projectRoot))[0]
            );
            $output->writeln('Magento installation completed!');
        } catch (InstallationDirectoryNotEmptyException | CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($e instanceof ProcessSignaledException && $e->getProcess()->isTerminated()) {
                return self::FAILURE;
            }

            $output->writeln("<error>An error appeared during installation: {$e->getMessage()}</error>");
            $output->writeln('Cleaning up the project composition and files...');
            $this->createProject->cleanup($projectRoot);

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
     * @throws ExceptionInterface
     */
    private function buildCompositionFromTemplate(
        ArgvInput|ArrayInput $input,
        OutputInterface $output,
        array $additionalOptions
    ): void {
        // Proxy all input and output, because we have variable amount of parameters and all of them must be passed
        // to the command `composition:build-from-template`
        $command = $this->getApplication()?->find($additionalOptions['command'])
            ?? throw new \LogicException('Application is not initialized');

        if ($command->run($this->buildInput($input, $additionalOptions, $input->isInteractive()), $output)) {
            throw new \RuntimeException('Can\'t build composition for the project');
        }
    }

    /**
     * @param ArgvInput|ArrayInput $input
     * @param array $additionalOptions
     * @param bool $isInteractive
     * @return ArgvInput
     */
    private function buildInput(
        ArgvInput|ArrayInput $input,
        array $additionalOptions = [],
        bool $isInteractive = true
    ): ArgvInput {
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

        uksort($inputArray, static function (string $a) {
            return (int) str_starts_with($a, '--with-');
        });

        // Convert array to ArgvInput to properly parse combined flags like `-nf` instead of `-n -f`
        $inputArrayForArgvInput = [''];

        foreach ($inputArray as $optionName => $value) {
            $value = is_array($value) ? implode(' ', $value) : $value;
            $value = is_bool($value) ? ($value ? '1' : '0') : $value;
            $inputArrayForArgvInput[] = is_null($value) ? $optionName : $optionName . '=' . $value;
        }

        $input = new ArgvInput($inputArrayForArgvInput);
        $input->setInteractive($isInteractive);

        return $input;
    }
}
