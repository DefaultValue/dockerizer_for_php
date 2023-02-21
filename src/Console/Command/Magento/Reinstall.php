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

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Composition as CommandOptionComposition;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class Reinstall extends \DefaultValue\Dockerizer\Console\Command\AbstractCompositionAwareCommand
{
    protected static $defaultName = 'magento:reinstall';

    /**
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     * @param \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Additional validation to ask less question in case of issues
        $this->magento->validateIsMagento(getcwd() . DIRECTORY_SEPARATOR);
        $this->setupInstall->setupInstall($output, $this->selectComposition($input, $output));

        return self::SUCCESS;
    }
}
