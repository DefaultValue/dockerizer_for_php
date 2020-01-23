<?php
declare(strict_types=1);

namespace App\Service;

class Database
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \PDO $connection
     */
    private static $connection;

    /**
     * @var string $mysqlVersion
     */
    private static $mysqlVersion;

    /**
     * Database constructor.
     * @param \App\Config\Env $env
     */
    public function __construct(
        \App\Config\Env $env
    ) {
        $this->env = $env;
        // Validate connection directly on startup to ensure that the whole tool configuration is correct
        $this->getConnection();
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

        $sql = <<<SQL
            INSERT INTO $table ($columns) VALUES ($values)
SQL;
        $connection->exec($sql);

        $this->unUse();

        return $this;
    }

    /**
     * The method is public because connection is established on startup to ensure that
     * .env file contains correct connection params
     *
     * We do not use the "USE <database>" to keep the connection stateless and reusable,
     * but maybe will implement connection pool or open/close new connection later if needed
     *
     * @return \PDO
     */
    public function getConnection(): \PDO
    {
        if (!isset(self::$connection)) {
            $host = $this->env->getDatabaseHost();
            $port = $this->env->getDatabasePort();

            self::$connection = new \PDO(
                "mysql:host=$host;port=$port;charset=utf8;",
                $this->env->getDatabaseUser(),
                $this->env->getDatabasePassword(),
                [
                    \PDO::ERRMODE_EXCEPTION
                ]
            );
        }

        return self::$connection;
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
        $randomDatabaseName = uniqid('db_');

        $connection->exec("CREATE DATABASE $randomDatabaseName");
        $connection->exec("USE $randomDatabaseName");
        $connection->exec("DROP DATABASE $randomDatabaseName");
    }
}
