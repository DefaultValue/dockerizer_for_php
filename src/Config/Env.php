<?php

declare(strict_types=1);

namespace App\Config;

class Env
{
    private const PROJECTS_ROOT_DIR = 'PROJECTS_ROOT_DIR';

    private const SSL_CERTIFICATES_DIR = 'SSL_CERTIFICATES_DIR';

    private const USER_ROOT_PASSWORD = 'USER_ROOT_PASSWORD';

    private const DATABASE_HOST = 'DATABASE_HOST';

    private const DATABASE_PORT = 'DATABASE_PORT';

    private const DATABASE_USER = 'DATABASE_USER';

    private const DATABASE_PASSWORD = 'DATABASE_PASSWORD';

    /**
     * Env constructor.
     */
    public function __construct()
    {
        $this->validateEnv();
    }

    /**
     * @return string
     */
    public function getProjectsRootDir(): string
    {
        return rtrim($this->getEnv(self::PROJECTS_ROOT_DIR), '\\/') . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getSslCertificatesDir(): string
    {
        return rtrim($this->getEnv(self::SSL_CERTIFICATES_DIR), '\\/') . DIRECTORY_SEPARATOR;
    }

    /**
     * @param bool $escape
     * @return string
     */
    public function getUserRootPassword(bool $escape = true): string
    {
        $password = $this->getEnv(self::USER_ROOT_PASSWORD);

        return $escape ? escapeshellarg($password) : $password;
    }

    /**
     * @return string
     */
    public function getDatabaseHost(): string
    {
        return $this->getEnv(self::DATABASE_HOST);
    }
    /**
     * @return string
     */
    public function getDatabasePort(): string
    {
        return $this->getEnv(self::DATABASE_PORT);
    }
    /**
     * @return string
     */
    public function getDatabaseUser(): string
    {
        return $this->getEnv(self::DATABASE_USER);
    }
    /**
     * @return string
     */
    public function getDatabasePassword(): string
    {
        return $this->getEnv(self::DATABASE_PASSWORD);
    }

    /**
     * Validate environment integrity for successful commands execution
     */
    private function validateEnv(): void
    {
        if (!$this->getUserRootPassword(false)) {
            throw new \RuntimeException('USER_ROOT_PASSWORD is not valid');
        }

        // @TODO: move executing external commands to the separate service, use it to test root password
        $exitCode = 0;

        passthru("echo {$this->getUserRootPassword()} | sudo -S echo \$USER > /dev/null", $exitCode);
        passthru("echo '\nRoot password verified'", $exitCode);

        if ($exitCode) {
            throw new \RuntimeException('Root password is not correct. Please, check configuration in ".env.local"');
        }
    }

    /**
     * @param string $variable
     * @return string
     */
    private function getEnv(string $variable): string
    {
        return trim(getenv($variable));
    }
}
