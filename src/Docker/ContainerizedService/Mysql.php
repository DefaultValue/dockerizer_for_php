<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

use DefaultValue\Dockerizer\Shell\Shell;

/**
 * Connect to MySQL from the host system via PDO
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

    private ?\PDO $connection;

    private const ERROR_CODE_CONNECTION_REFUSED = 2002;

    // Sleep for 1s and retry to connect in case MySQL server is still starting
    // It takes at least a few seconds till MySQL becomes available even in case the Docker service is running
    private const CONNECTION_RETRIES = 60;

    private string $tablePrefix;

    /**
     * @param string $containerName
     * @param string $tablePrefix
     * @return static
     */
    public function initialize(string $containerName, string $tablePrefix = ''): static
    {
        $self = parent::initialize($containerName);
        // Set connection immediately to ensure connection can be established successfully
        $self->getConnection();

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
        ) {
            throw new \InvalidArgumentException(
                'MySQL/MariaDB passwords with single or double quotes are not supported!'
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
     * Get MySQL version
     * @TODO: for now this return MariaDB version for MariaDB, so you need to know which server is used
     *
     * @return string
     */
    public function getMysqlVersion(): string
    {
        // 10.3.30-MariaDB
        return explode('-', $this->getConnection()->getAttribute(\PDO::ATTR_SERVER_VERSION))[0];
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
     * @param array $params
     * @return \PDOStatement
     */
    public function prepareAndExecute(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->getConnection()->prepare($sql);

        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value);
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param string $sql
     * @return void
     */
    public function exec(string $sql): void
    {
        $this->getConnection()->exec($sql);
    }

    /**
     * @param string $stringToQuote
     * @return string
     * @deprecated
     */
    public function quote(string $stringToQuote): string
    {
        return $this->getConnection()->quote($stringToQuote);
    }

    /**
     * @param string $destination
     * @param bool $removeDefiner
     * @param bool $compress
     * @return void
     */
    public function dump(string $destination, bool $removeDefiner = true, bool $compress = true): void
    {
        $dumpCommand = sprintf(
            'mysqldump -u%s -p%s --routines --events --triggers --no-tablespaces --insert-ignore --skip-lock-tables'
            . ' --single-transaction=TRUE %s',
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

        $dumpCommand .= ' > ' . $destination;
        $this->docker->mustRun(
            $dumpCommand,
            $this->getContainerName(),
            Shell::EXECUTION_TIMEOUT_LONG,
            false
        );
    }

    /**
     * @return \PDO
     */
    private function getConnection(): \PDO
    {
        if (!isset($this->connection)) {
            // @TODO: move checking services availability to `docker-compose up`
            $retries = self::CONNECTION_RETRIES;
            $dbUser = $this->getMysqlUser();
            $password = $this->getMysqlPassword();
            $database = $this->getMysqlDatabase();

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

            // Retry to connect if MySQL server is starting
            while ($retries-- && !isset($this->connection)) {
                try {
                    $this->connection = new \PDO(
                        sprintf(
                            'mysql:host=%s;port=%d;charset=utf8;dbname=%s',
                            $this->docker->getContainerIp($this->getContainerName()),
                            self::PORT,
                            $database
                        ),
                        $dbUser,
                        $password,
                        [
                            \PDO::ERRMODE_EXCEPTION
                        ]
                    );
                } catch (\PDOException $e) {
                    if (
                        $retries
                        && ($e->getCode() === self::ERROR_CODE_CONNECTION_REFUSED)
                    ) {
                        sleep(1);

                        continue;
                    }

                    throw $e;
                }
            }
        }

        return $this->connection;
    }
}
