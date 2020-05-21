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
     * Currently initialized by the \App\CommandQuestion\Question\MysqlContainer::ask()
     * Only commands that are aware of the MySQL container can connect to the database.
     * Port will be retrieved from the infrastructure composition and passed here.
     * Must use the same user/password for all databases and have the port exposed.
     *
     * @param string $port
     * @throws \PDOException
     */
    public function connect(string $port): void
    {
        $user = self::USER;
        $password = self::PASSWORD;
        $host = self::HOST;

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
     * @param string $domain
     */
    public function refreshDatabase(string $domain): void
    {
        $this->dropDatabase($domain);

        $databaseName = $this->getDatabaseName($domain);
        $databaseUser = $this->getDatabaseUsername($domain);

        $connection = $this->getConnection();
        $connection->exec("CREATE DATABASE $databaseName");
        $connection->exec("GRANT ALL ON $databaseName.* TO '$databaseUser'@'%' IDENTIFIED BY '$databaseName'");
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
        $randomDatabaseName = uniqid('db_', true);

        $connection->exec("CREATE DATABASE $randomDatabaseName");
        $connection->exec("USE $randomDatabaseName");
        $connection->exec("DROP DATABASE $randomDatabaseName");
    }
}
