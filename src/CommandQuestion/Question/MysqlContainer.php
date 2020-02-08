<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Choose from the available MySQL/MariaDB/etc. containers in the Traefik composition.
 * Initializes PDO connection for later.
 *
 * Class Database
 * @package App\CommandQuestion\Question
 */
class MysqlContainer extends \App\CommandQuestion\AbstractQuestion
{
    /**
     * @inheritDoc
     */
    public const QUESTION = 'mysql_container_question';

    /**
     * PHP version based on the available templates from the repo: https://github.com/DefaultValue/docker_infrastructure
     */
    public const OPTION_MYSQL_CONTAINER = 'mysql-container';
    /**
     * @var \App\Service\Database $database
     */
    private $database;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * PhpVersion constructor.
     * @param \App\Service\Database $database
     * @param \App\Service\Shell $shell
     */
    public function __construct(
        \App\Service\Database $database,
        \App\Service\Shell $shell
    ) {
        $this->database = $database;
        $this->shell = $shell;
    }

    /**
     * @inheritDoc
     */
    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_MYSQL_CONTAINER,
            null,
            InputOption::VALUE_REQUIRED,
            'PHP version: from 5.6 to 7.4',
            'mysql57'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param bool $noInteraction
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        bool $noInteraction = false
    ): string {
        if ($mysqlContainer = (string) $input->getOption(self::OPTION_MYSQL_CONTAINER)) {
            $this->database->connect($this->getPort($mysqlContainer));
        }





        $availablePhpVersions = $this->filesystem->getAvailablePhpVersions();
        $phpVersion = $input->getOption(self::OPTION_PHP_VERSION)
            ? number_format((float) $input->getOption(self::OPTION_PHP_VERSION), 1)
            : false;

        if ($phpVersion && !in_array($phpVersion, $availablePhpVersions, true)) {
            $output->writeln('<error>Provided PHP version is not available!</error>');
            $phpVersion = false;
        }

        if (!$phpVersion) {
            if (!empty($allowedPhpVersions)) {
                $availablePhpVersions = array_intersect($allowedPhpVersions, $availablePhpVersions);
            }

            if (empty($availablePhpVersions)) {
                throw new \RuntimeException(
                    'Can not find a suitable PHP version! ' .
                    'Please, contact the repository maintainer ASAP (see composer.json for authors)'
                );
            }

            if ($noInteraction) {
                $phpVersion = array_pop($availablePhpVersions);
            } else {
                $question = new ChoiceQuestion(
                    '<info>Select PHP version:</info>',
                    $availablePhpVersions
                );
                $question->setErrorMessage('PHP version %s is invalid');
                $phpVersion = $questionHelper->ask($input, $output, $question);
            }

            $output->writeln(
                "<info>You have selected the following PHP version: </info><fg=blue>$phpVersion</fg=blue>"
            );
        }

        return $phpVersion;
    }

    private function getPort(string $mysqlContainer): string
    {
        return $this->shell->shellExec(
            "docker inspect --format='{{(index (index .NetworkSettings.Ports \"3306/tcp\") 0).HostPort}}' $mysqlContainer"
        );
    }
}
