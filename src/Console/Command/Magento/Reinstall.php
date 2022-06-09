<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Composition as CommandOptionComposition;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\UniversalReusableOption;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Reinstall extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'magento:reinstall';

    protected array $commandSpecificOptions = [
        CommandOptionComposition::OPTION_NAME,
    ];

    /**
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     * @param \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable $commandArguments
     * @param iterable $availableCommandOptions
     * @param UniversalReusableOption $universalReusableOption
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Platform\Magento $magento,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
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
        $this->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->addArgument(
                CommandOptionComposition::ARGUMENT_COLLECTION_FILTER,
                InputArgument::OPTIONAL,
                'Choose only from compositions containing this string'
            )
            ->setHelp(<<<'EOF'
                Run <info>%command.name%</info> in the Magento root folder to reinstall Magento application.
                This is especially useful for testing modules.
                Magento will not be configured to use Redis other services!

                Simple usage:

                    <info>php %command.full_name%</info>

                IMPORTANT! Only running Magento instance can be reinstalled. If something goes wrong then it is
                better to install the system again or fix issues manually.
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
        $this->magento->validateIsMagento(); // Just do nothing if we're not in the Magento dir
        $filter = (string) $input->getArgument(CommandOptionComposition::ARGUMENT_COLLECTION_FILTER);
        /** @var CommandOptionComposition $commandOptionComposition */
        $commandOptionComposition = $this->getCommandSpecificOption(CommandOptionComposition::OPTION_NAME);
        $commandOptionComposition->setFilter($filter);
        $dockerCompose = $this->getOptionValueByOptionName($input, $output, CommandOptionComposition::OPTION_NAME);
        $collection = $this->compositionCollection->getList('', $dockerCompose);
        $this->setupInstall->setupInstall($output, $collection[$dockerCompose]);

        return self::SUCCESS;
    }
}
