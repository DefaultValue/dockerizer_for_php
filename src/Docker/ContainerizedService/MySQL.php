<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService;

/**
 * Connect to MySQL from the host system via PDO
 */
class MySQL extends AbstractService
{
    // @TODO: find a way to use secure MySQL root passwords!
    private const USER = 'root';
    private const PASSWORD = 'root';
    private const PORT = '3306';

    private ?\PDO $connection;

    private const ERROR_CODE_CONNECTION_REFUSED = 2002;

    // Sleep for 1s and retry to connect in case MySQL server is still starting
    // It takes at least a few seconds till MySQL becomes available even in case the Docker service is running
    private const CONNECTION_RETRIES = 60;

    /**
     * @param string $containerName
     * @return $this
     */
    public function initialize(string $containerName): static
    {
        $self = parent::initialize($containerName);
        // Set connection immediately to ensure connection can be established successfully
        $self->getConnection();

        return $self;
    }

    /**
     * Connect to a particular database
     *
     * @param string $dbName
     * @return void
     */
    public function useDatabase(string $dbName): void
    {
        $this->getConnection()->exec("USE `$dbName`");
    }

    public function unUseDatabase(): void
    {
        $connection = $this->getConnection();
        $randomDatabaseName = str_replace('.', '_', uniqid('db_', true));
        $connection->exec("CREATE DATABASE $randomDatabaseName");
        $connection->exec("USE $randomDatabaseName");
        $connection->exec("DROP DATABASE $randomDatabaseName");
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
     * @param string $sql
     * @return void
     */
    public function exec(string $sql): void
    {
        $this->getConnection()->exec($sql);
    }

    /**
     * @param string $sql
     * @param array $params
     * @return void
     */
    public function prepareAndExecute(string $sql, array $params): void
    {
        $statement = $this->getConnection()->prepare($sql);

        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value);
        }

        $statement->execute();
    }

    /**
     * @return \PDO
     */
    private function getConnection(): \PDO
    {
        if (!isset($this->connection)) {
            // @TODO: move checking services availability to `docker-compose up`
            $retries = self::CONNECTION_RETRIES;

            // Retry to connect if MySQL server is starting
            while ($retries-- && !isset($this->connection)) {
                try {
                    $this->connection = new \PDO(
                        sprintf(
                            'mysql:host=%s;port=%d;charset=utf8;',
                            $this->docker->getContainerIp($this->getContainerName()),
                            self::PORT
                        ),
                        self::USER,
                        self::PASSWORD,
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
