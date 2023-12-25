<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Docker\Container;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Work with MySQL and MariaDB containers using the `docker exec` command and the internal `mysql` client
 * Requires MySQL or MariaDB environment variables to be set
 */
class Mysql extends AbstractService
{
    public const MYSQL_ROOT_PASSWORD = 'MYSQL_ROOT_PASSWORD';
    private const MYSQL_DATABASE = 'MYSQL_DATABASE';
    private const MYSQL_USER = 'MYSQL_USER';
    public const MYSQL_PASSWORD = 'MYSQL_PASSWORD';

    // Used for MariaDB only. MySQL auto-generates random password for root user
    // Do not use root access
    public const MARIADB_ROOT_PASSWORD = 'MARIADB_ROOT_PASSWORD';
    private const MARIADB_DATABASE = 'MARIADB_DATABASE';
    private const MARIADB_USER = 'MARIADB_USER';
    public const MARIADB_PASSWORD = 'MARIADB_PASSWORD';

    // Used for phpMyAdmin only
    public const PMA_PASSWORD = 'PMA_PASSWORD';

    private const PORT = '3306';

    private bool $connectionAvailable;

    // These errors often indicate that MySQL server is still starting
    private const ERROR_CONNECTION_REFUSED = 'ERROR 2002';
    private const ERROR_ACCESS_DENIED = 'ERROR 1045 (28000)';

    /**
     * Sleep for 1s and retry to connect in case MySQL server is still starting
     * It takes from seconds to hours for MySQL becomes available even in case the Docker service is running
     * Hours in case of importing a database on startup
     */
    private const CONNECTION_RETRIES = 60;

    /**
     * Number of possible checks in case the container is not `running`
     * 10 check and still not `running` - fail
     * 10 times restarting without a result - fail
     * Especially important for CI/CD or other tasks when expected MySQL startup time is high.
     * In this case, exited or restarting container may lead to a ver long wait time instead of failing.
     * For sure, this is not a great way to check the container health, and it would be better to check logs or watch
     * state changes. Current implementation should be enough.
     */
    private const STATE_CONNECTION_RETRIES = 10;

    private string $tablePrefix;

    /**
     * @param string $containerName
     * @param string $tablePrefix
     * @param int $connectionRetries - sleep for 1s and retry to connect. Useful for cases when DB is imported from the
     * dump using the MySQL entrypoint script
     * @return static
     */
    public function initialize(
        string $containerName,
        string $tablePrefix = '',
        int $connectionRetries = self::CONNECTION_RETRIES
    ): static {
        $self = parent::initialize($containerName);
        // Set connection immediately to ensure connection can be established successfully
        $self->testConnection($connectionRetries);

        if ($tablePrefix) {
            $self->tablePrefix = $tablePrefix;
        }

        return $self;
    }

    /**
     * @return string
     */
    public function getMysqlDatabase(): string
    {
        $database = $this->getEnvironmentVariable(self::MYSQL_DATABASE)
            ?: $this->getEnvironmentVariable(self::MARIADB_DATABASE);

        if (!$database) {
            throw new \RuntimeException('MySQL/MariaDB database name is unknown!');
        }

        return $database;
    }

    /**
     * @return string
     */
    public function getMysqlUser(): string
    {
        $user = $this->getEnvironmentVariable(self::MYSQL_USER)
            ?: $this->getEnvironmentVariable(self::MARIADB_USER);

        if (!$user) {
            throw new \RuntimeException('MySQL/MariaDB user is not set!');
        }

        return $user;
    }

    /**
     * @return string
     */
    public function getMysqlPassword(): string
    {
        $password = $this->getEnvironmentVariable(self::MYSQL_PASSWORD)
            ?: $this->getEnvironmentVariable(self::MARIADB_PASSWORD);

        if (!$password) {
            throw new \RuntimeException('MySQL/MariaDB password is not set!');
        }

        if (
            str_contains($password, "'")
            || str_contains($password, '"')
            || str_contains($password, '\'')
        ) {
            throw new \InvalidArgumentException(
                'The following chars in MySQL/MariaDB passwords are not supported: '
                . 'single and double quotes, backslash'
            );
        }

        return $password;
    }

    /**
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getTableName(string $tableName): string
    {
        return $this->tablePrefix . $tableName;
    }

    /**
     * @param string $sql
     * @param bool $addDatabaseName
     * @return Process
     */
    public function exec(string $sql, bool $addDatabaseName = false): Process
    {
        if (!isset($this->connectionAvailable)) {
            throw new \RuntimeException('Call MySQL::initialize() to create a service instance');
        }

        return $this->mustRun(
            sprintf(
                '%s -Be %s',
                $this->getMysqlClientConnectionString($addDatabaseName),
                escapeshellarg($sql)
            ),
            Shell::EXECUTION_TIMEOUT_SHORT,
            false
        );
    }

    /**
     * Use Docker exec to fetch data and convert it to the PHP array
     * Note that the result array keys are lowercase
     *
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    public function fetchArray(string $sql): array
    {
        if (!isset($this->connectionAvailable)) {
            throw new \RuntimeException('Call MySQL::initialize() to create a service instance');
        }

        $process = $this->exec($sql, true);
        $output = trim($process->getOutput());
        $result = [];

        if (!$output) {
            return $result;
        }

        $outputArray = explode(PHP_EOL, $output);
        $keys = explode("\t", array_shift($outputArray));
        // Lowercase keys
        $keys = array_map('strtolower', $keys);

        foreach ($outputArray as $row) {
            $result[] = array_combine($keys, explode("\t", $row));
        }

        return $result;
    }

    /**
     * @param string $stringToQuote
     * @return string
     * @deprecated
     */
    public function quote(string $stringToQuote): string
    {
        return $this->testConnection()->quote($stringToQuote);
    }

    /**
     * @param string $destination - Host OS path
     * @param bool $removeDefiner
     * @param bool $compress
     * @return void
     */
    public function dump(
        string $destination = '',
        bool $removeDefiner = true,
        bool $compress = true
    ): void {
        $this->mustRun(
            $this->getDumpCommand($destination, $removeDefiner, $compress),
            Shell::EXECUTION_TIMEOUT_LONG,
            false
        );
    }

    /**
     * @param string $destination - Host OS path
     * @param bool $removeDefiner
     * @param bool $compress
     * @return string
     */
    public function getDumpCommand(
        string $destination = '',
        bool $removeDefiner = true,
        bool $compress = true,
    ): string {
        $dumpCommand = sprintf(
            'mysqldump -u%s -p%s --routines --events --triggers --no-tablespaces --insert-ignore --skip-lock-tables %s',
            $this->getMysqlUser(),
            escapeshellarg($this->getMysqlPassword()),
            $this->getMysqlDatabase()
        );

        if ($removeDefiner) {
            $dumpCommand .= ' | sed \'s/DEFINER=[^*]*\*/\*/g\'';
        }

        if ($compress) {
            $dumpCommand .= ' | gzip';
        }

        if (!$destination) {
            $destination = $this->getMysqlDatabase() . '_' . date('Y-m-d_H-i-s') . '.sql';
            $destination .= $compress ? '.gz' : '';
        }

        $dumpCommand .= ' > ' . $destination;

        return $dumpCommand;
    }

    /**
     * @param bool $addDatabaseName
     * @return string
     */
    public function getMysqlClientConnectionString(bool $addDatabaseName = true): string
    {
        $dbUser = $this->getMysqlUser();
        $password = $this->getMysqlPassword();
        $database = $addDatabaseName ? ' ' . $this->getMysqlDatabase() : '';

        if (!$dbUser || !$password) {
            // These environment variables must be present in the `docker-compose.yaml` file
            throw new \InvalidArgumentException(
                sprintf(
                    'MySQL user or password missed! Checked environment variables: %s  %s, %s, %s',
                    self::MYSQL_USER,
                    self::MARIADB_USER,
                    self::MYSQL_PASSWORD,
                    self::MARIADB_PASSWORD
                )
            );
        }

        return sprintf(
            'mysql --show-warnings -P%d -u%s -p%s%s',
            self::PORT,
            $dbUser,
            escapeshellarg($password),
            $database
        );
    }

    /**
     * @param int $connectionRetries
     * @return void
     */
    private function testConnection(int $connectionRetries = self::CONNECTION_RETRIES): void
    {
        if (!isset($this->connectionAvailable)) {
            $this->connectionAvailable = false;
            $stateConnectionRetries = min($connectionRetries, self::STATE_CONNECTION_RETRIES);

            // Retry to connect if MySQL server is starting
            while ($connectionRetries-- && !isset($this->connection)) {
                try {
                    if ($this->getState() !== Container::CONTAINER_STATE_RUNNING) {
                        --$stateConnectionRetries;
                    }

                    if (!$stateConnectionRetries) {
                        throw new ContainerStateException(
                            '',
                            0,
                            null,
                            $this->getContainerName(),
                            Container::CONTAINER_STATE_RUNNING
                        );
                    }

                    $this->exec('SELECT 1');
                    $this->connectionAvailable = true;

                    return;
                } catch (ProcessFailedException $e) {
                    if (
                        $connectionRetries
                        && (
                            str_contains($e->getProcess()->getErrorOutput(), self::ERROR_CONNECTION_REFUSED)
                            || str_contains($e->getProcess()->getErrorOutput(), self::ERROR_ACCESS_DENIED)
                        )
                    ) {
                        sleep(1);

                        continue;
                    }

                    throw $e;
                }
            }
        }
    }
}
