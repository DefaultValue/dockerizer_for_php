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
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Reinstall extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'magento:reinstall';

    /**
     * @param \Composer\Semver\VersionParser $versionParser
     * @param \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param UniversalReusableOption $universalReusableOption
     * @param string|null $name
     */
    public function __construct(
        private \Composer\Semver\VersionParser $versionParser,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        iterable $commandArguments,
        iterable $availableCommandOptions,
        UniversalReusableOption $universalReusableOption,
        string $name = null
    ) {
        // Ignore validation error not to fail when unknown options are passed
        // Required for passing all options to the command `composition:build-from-template`
//        $this->ignoreValidationErrors();
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
                Run <info>%command.name%</info> in the Magento root folder to reinstall Magento application.
                This is especially useful for testing modules.
                Magento will not be configured to use Redis, Varnish Elasticsearch or other services!

                Simple usage:

                    <info>php %command.full_name%</info>
                EOF);

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
        /**
         * check this is Magento!
         * pass env, check it exists and is running...?
         * get magento version from the package
         * get DB params like DB name, user, password, prefix
         */

        $collection = $this->composition->getDockerComposeCollection(getcwd() . DIRECTORY_SEPARATOR);

        $this->setupInstall->setupInstall(
            $output,
            $collection[0]
        );

        exit;

        // Prepare composition files to run and install Magento inside
        // Proxy domains and other parameters so that the user is not asked the same question again
        // Do not dump composition - installer will do this when needed

        // Install Magento
        $this->magentoInstaller->setupInstall($output, $domains);


        // @TODO: Choose first service from every available in case we're not in the interactive mode?
        // Shutdown - get all composition services to be able to shut down them in case of execution errors

        return self::SUCCESS;
    }
}
