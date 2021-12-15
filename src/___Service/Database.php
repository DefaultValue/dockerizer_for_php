<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Class Database
 *
 * Low-level database operations that are not related to some particular command.
 */
class Database
{
    private const HOST = '127.0.0.1';

    private const USER = 'root';

    private const PASSWORD = 'root';

    /**
     * @var \PDO $connection
     */
    private static $connection;

    /**
     * @var string $mysqlVersion
     */
    private static $mysqlVersion;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * Database constructor.
     * @param \App\Service\Shell $shell
     */
    public function __construct(\App\Service\Shell $shell)
    {
        $this->shell = $shell;
    }

    /**
     * Currently initialized by the \App\CommandQuestion\Question\MysqlContainer::ask()
     * Only commands that are aware of the MySQL container can connect to the database.
     * Port will be retrieved from the infrastructure composition and passed here.
     * Must use the same user/password for all databases and have the port exposed.
     *
     * @param string $container
     * @throws \PDOException
     */
    public function connect(string $container): void
    {
        $user = self::USER;
        $password = self::PASSWORD;
        $host = self::HOST;
        $port = $this->getPort($container);

        self::$connection = new \PDO(
            "mysql:host=$host;port=$port;charset=utf8;",
            $user,
            $password,
            [
                \PDO::ERRMODE_EXCEPTION
            ]
        );
    }

    /**
     * We do not use the "USE <database>" to keep the connection stateless and reusable,
     * but maybe will implement connection pool or open/close new connection later if needed
     *
     * @return \PDO
     */
    public function getConnection(): \PDO
    {
        if (!isset(self::$connection)) {
            throw new \PDOException('You must first call ::connect() to create a working connection!');
        }

        return self::$connection;
    }

    /**
     * Get MySQL container port from the docker meta information
     * @param string $mysqlContainer
     * @return string
     */
    public function getPort(string $mysqlContainer): string
    {
        // Should cache results
        // Maybe better to `docker-compose port mysql57 3306` returns '0.0.0.0:3357'
        $port = $this->shell->exec(
            "docker inspect --format='{{(index (index .NetworkSettings.Ports \"3306/tcp\") 0).HostPort}}' "
            . $mysqlContainer
        );

        return (string) $port[0];
    }

    /**
     * @param string $domain
     * @param bool $useMysqlNativePassword
     */
    public function refreshDatabase(string $domain, bool $useMysqlNativePassword): void
    {
        $this->dropDatabase($domain);

        $databaseName = $this->getDatabaseName($domain);
        $databaseUser = $this->getDatabaseUsername($domain);

        $connection = $this->getConnection();
        $connection->exec("CREATE DATABASE $databaseName");

        if ($useMysqlNativePassword) {
            $connection->exec("CREATE USER IF NOT EXISTS '$databaseUser'@'%' IDENTIFIED WITH mysql_native_password BY '$databaseName'");
        } else {
            $connection->exec("CREATE USER IF NOT EXISTS '$databaseUser'@'%' IDENTIFIED BY '$databaseName'");
        }

        $connection->exec("GRANT ALL ON $databaseName.* TO '$databaseUser'@'%'");
    }

    /**
     * @param string $domain
     */
    public function dropDatabase(string $domain): void
    {
        $this->getConnection()->exec("DROP DATABASE IF EXISTS {$this->getDatabaseName($domain)}");
    }

    /**
     * Keep database name, user and pass the same. Max user name length is 16 symbols, so need to trim value
     *
     * @param string $domain
     * @return string
     */
    public function getDatabaseName(string $domain): string
    {
        $databaseName = str_replace(['.', '-'], '_', $domain);
        $databaseName = substr($databaseName, 0, 64);

        return $databaseName;
    }

    /**
     * Limit length to 16 only for MySQL 5.6 or to 32 for 5.7.8+
     *
     * @param string $domain
     * @return string
     */
    public function getDatabaseUsername(string $domain): string
    {
        $maxUserNameLength = version_compare($this->getMysqlVersion(), '5.7.8', '>=') ? 32 : 16;

        return substr($this->getDatabaseName($domain), 0, $maxUserNameLength);
    }

    /**
     * The method is not effective and executed excessive SQL queries to unuse database
     * Though, for now this is fine because we do not require real optimization here
     * Must change this method and the whole database layer later if needed
     *
     * @param string $domain
     * @param string $table
     * @param array $data
     * @return $this
     */
    public function insertKeyValue(string $domain, string $table, array $data): self
    {
        $databaseName = $this->getDatabaseName($domain);
        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $values = '\'' . implode('\', \'', array_values($data)) . '\'';

        $connection = $this->getConnection();
        $connection->exec("USE $databaseName");

        $sql = "INSERT INTO $table ($columns) VALUES ($values)";
        $connection->exec($sql);

        $this->unUse();

        return $this;
    }

    /**
     * @return string
     */
    public function getMysqlVersion(): string
    {
        if (self::$mysqlVersion === null) {
            self::$mysqlVersion = $this->getConnection()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        }

        return self::$mysqlVersion;
    }

    /**
     * "Unuse" database top keep the connection stateless - https://stackoverflow.com/a/34425220
     */
    private function unUse(): void
    {
        $connection = $this->getConnection();
        $randomDatabaseName = str_replace('.', '_', uniqid('db_', true));

        $connection->exec("CREATE DATABASE $randomDatabaseName");
        $connection->exec("USE $randomDatabaseName");
        $connection->exec("DROP DATABASE $randomDatabaseName");
    }
}
