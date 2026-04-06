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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * @noinspection PhpUnused
 */
#[AsCommand(
    name: 'docker:mysql:connect',
    description: 'Connect to MySQL database with the MySQL client from the given container',
)]
class Connect extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
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
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        iterable $availableCommandOptions,
    ) {
        parent::__construct($availableCommandOptions);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setHelp(<<<'EOF'
                Example usage:

                    <info>php %command.full_name% -c <mysql_container_name> -e</info>

                EOF);

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        $execute = $this->getCommandSpecificOptionValue($input, $output, CommandOptionExec::OPTION_NAME);

        if ($execute && !$input->isInteractive()) {
            throw new \InvalidArgumentException(
                sprintf('The option \'--%s\' can only be used in the interactive mode', CommandOptionExec::OPTION_NAME)
            );
        }

        $mysqlContainerName = $this->getCommandSpecificOptionValue(
            $input,
            $output,
            CommandOptionDockerContainer::OPTION_NAME
        );
        // @TODO: get all running MySQL container with the respective environment variables and suggest to choose
        $mysqlService = $this->mysql->initialize($mysqlContainerName);
        $mysqlClientConnectionString = sprintf(
            'docker exec -it %s %s',
            escapeshellarg($mysqlService->getContainerName()),
            $mysqlService->getMysqlClientConnectionString()
        );

        unset($mysqlService);

        if ($execute) {
            // @TODO: run this with Shell or Docker. Right now this is problematic due to the `-it` + `tty` combination
            // Other commands will break if we add `-it` when `$tty = true`. This requires testing and code changes.
            $output->writeln('Connecting to MySQL database...');
            $process = Process::fromShellCommandline(
                $mysqlClientConnectionString,
                null,
                [],
                null,
                null
            );
            $process->setTty(true);
            $process->mustRun();
        } else {
            $output->write($mysqlClientConnectionString);
        }

        return self::SUCCESS;
    }
}
