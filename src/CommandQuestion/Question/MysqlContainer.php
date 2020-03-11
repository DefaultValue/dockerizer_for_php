<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use App\Service\Filesystem;
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
     * MySQL containers from Docker-based infrastructure: https://github.com/DefaultValue/docker_infrastructure
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
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * MysqlContainer constructor.
     * @param \App\Service\Database $database
     * @param \App\Service\Shell $shell
     * @param \App\Config\Env $env
     * @param \App\Service\Filesystem $filesystem
     */
    public function __construct(
        \App\Service\Database $database,
        \App\Service\Shell $shell,
        \App\Config\Env $env,
        \App\Service\Filesystem $filesystem
    ) {
        $this->database = $database;
        $this->shell = $shell;
        $this->env = $env;
        $this->filesystem = $filesystem;
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
            'MySQL container from local docker env. Service name must contain "mysql", "maria" or "percona"!'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    ): string {
        // Try to connect to the provided container
        $mysqlContainer = (string) $input->getOption(self::OPTION_MYSQL_CONTAINER);

        if ($mysqlContainer && $this->connect($mysqlContainer)) {
            return $mysqlContainer;
        }

        // Otherwise - ask to select
        if (!$availableMysqlContainers = $this->getMysqlContainers()) {
            throw new \PDOException(
                'No MySQL containers found. Ensure the Docker infrastructure is running.'
            );
        }

        $containersWithPort = [];

        foreach ($availableMysqlContainers as $availableContainer) {
            $containersWithPort["$availableContainer (port {$this->getPort($availableContainer)})"]
                = $availableContainer;
        }

        $defaultMysqlContainer = $this->env->getDefaultDatabaseContainer()
            . " (port {$this->getPort($this->env->getDefaultDatabaseContainer())})";
        $question = new ChoiceQuestion(
            '<info>Select MySQL container to link. ' .
            "Press Enter to use <fg=blue>$defaultMysqlContainer</fg=blue></info>",
            array_keys($containersWithPort),
            $defaultMysqlContainer
        );

        $question->setErrorMessage('Invalid MySQL container selected: %s');

        // Question is not asked in the no-interaction mode
        $mysqlContainer = ($containerWithPort = $questionHelper->ask($input, $output, $question))
            ? $containersWithPort[$containerWithPort]
            : $defaultMysqlContainer;

        if (!$this->connect($mysqlContainer)) {
            throw new \PDOException("Can't connect to the following MySQL container: $mysqlContainer");
        }

        $output->writeln(
            "<info>Using the following MySQL container: </info><fg=blue>$mysqlContainer</fg=blue>\n"
        );

        return $mysqlContainer;
    }

    /**
     * @return array
     */
    private function getMysqlContainers(): array
    {
        $localInfrastructureDir = $this->filesystem->getDirPath(Filesystem::DIR_LOCAL_INFRASTRUCTURE);
        $mysqlContainers = $this->shell->exec("cd $localInfrastructureDir && docker-compose ps --services");
        return array_filter($mysqlContainers, static function ($value) {
            return preg_match('/maria|mysql|percona/', $value);
        });
    }

    /**
     * Get MySQL container port from the docker meta information
     * @param string $mysqlContainer
     * @return string
     */
    private function getPort(string $mysqlContainer): string
    {
        // Maybe better to `docker-compose port mysql57 3306` returns '0.0.0.0:3357'
        $port = $this->shell->exec(
            "docker inspect --format='{{(index (index .NetworkSettings.Ports \"3306/tcp\") 0).HostPort}}' $mysqlContainer",
        );

        return (string) $port[0];
    }

    /**
     * Try to connect to the database.
     * @param string $container
     * @return bool
     */
    private function connect(string $container): bool
    {
        try {
            $this->database->connect($this->getPort($container));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
