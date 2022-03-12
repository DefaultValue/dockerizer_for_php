<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Runner as CommandOptionRunner;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\UniversalReusableOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetUp extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    private const MAGENTO_CE_PACKAGE = 'magento/product-community-edition';

    protected static $defaultName = 'magento:setup';

    protected array $commandSpecificOptions = [
//        CommandOptionDomains::OPTION_NAME,
        CommandOptionCompositionTemplate::OPTION_NAME,
//        CommandOptionRunner::OPTION_NAME,
//        CommandOptionRequiredServices::OPTION_NAME,
//        CommandOptionOptionalServices::OPTION_NAME,
        CommandOptionForce::OPTION_NAME
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param UniversalReusableOption $universalReusableOption
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        iterable $commandArguments,
        iterable $availableCommandOptions,
        UniversalReusableOption $universalReusableOption,
        string $name = null
    ) {
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
                Simple usage:

                    <info>php %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local"</info>

                Install Magento with the pre-defined PHP version and MySQL container:

                    <info>php %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local" --php=7.3 --mysql-container=mysql57</info>
                EOF)
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Semantic Magento version like 2.3.3-p1, 2.4.4 etc.'
            );

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Preset package info to get recommended tempaltes if possible
        $magentoVersion = $input->getArgument('version');
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

        $buildFromTemplateCommand = $this->getApplication()->find('composition:build-from-template');

        // Choose first service from every available in case we're not in the interactive mode?
        // Pass all input parameters to the build-from-template?
        // Install

        return self::SUCCESS;
    }
}
