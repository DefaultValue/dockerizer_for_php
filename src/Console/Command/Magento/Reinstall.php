<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Composition as CommandOptionComposition;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Reinstall extends \DefaultValue\Dockerizer\Console\Command\AbstractCompositionAwareCommand
{
    protected static $defaultName = 'magento:reinstall';

    /**
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     * @param \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Platform\Magento $magento,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $availableCommandOptions,
            $name
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('Reinstall Magento packed inside the Docker container')
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
        $this->setupInstall->setupInstall($output, $this->selectComposition($input, $output));

        return self::SUCCESS;
    }
}
