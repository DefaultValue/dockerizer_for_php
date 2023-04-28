<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Container as CommandOptionDockerContainer;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Exec as CommandOptionExec;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @noinspection PhpUnused
 */
class ExportDb extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'docker:mysql:export-db';

    /**
     * @inheritdoc
     */
    protected array $commandSpecificOptions = [
        CommandOptionDockerContainer::OPTION_NAME,
        CommandOptionExec::OPTION_NAME
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setDescription('Dump database from the MySQL Docker container')
            ->setHelp(<<<'EOF'
                Dump is saved to the current directory. By default, this command will return a dump command instead of executing it. To execute the dump command, use the <info>--force</info> option.
                Example usage to create archived dump file:

                    <info>php %command.full_name% -c <mysql_container_name> -a -f</info>

                Dump name includes DB name, date and time for easier file identification. For example: <info>db_name_Y-m-d_H-i-s.sql</info>
                EOF)
            ->addOption(
                'archive', # Call it `archive` because `-c` is already taken for `--container`
                'a',
                InputOption::VALUE_NONE,
                'Archive dump file'
            );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws \Exception
     * @throws ExceptionInterface
     */
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        $mysqlContainerName = $this->getCommandSpecificOptionValue(
            $input,
            $output,
            CommandOptionDockerContainer::OPTION_NAME
        );
        // @TODO: get all running MySQL container with the respective environment variables and suggest to choose

        $mysqlService = $this->mysql->initialize($mysqlContainerName);

        if ($this->getCommandSpecificOptionValue($input, $output, CommandOptionExec::OPTION_NAME)) {
            $mysqlService->dump('', true, $input->getOption('archive'));
        } else {
            $mysqlDumpCommand = sprintf(
                'docker exec %s %s',
                escapeshellarg($mysqlService->getContainerName()),
                $mysqlService->getDumpCommand('', true, $input->getOption('archive'))
            );
            $output->write($mysqlDumpCommand);
        }

        return self::SUCCESS;
    }
}
