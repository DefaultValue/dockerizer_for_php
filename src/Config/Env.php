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
        return (string) getenv(self::PROJECTS_ROOT_DIR);
    }

    /**
     * @param string $dir
     * @return string
     */
    private function getDir(string $dir): string
    {
        return rtrim($this->getProjectsRootDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($dir, DIRECTORY_SEPARATOR);
    }

    /**
     * @return string
     */
    public function getSslCertificatesDir(): string
    {
        return (string) getenv(self::SSL_CERTIFICATES_DIR);
    }

    /**
     * @param bool $escape
     * @return string
     */
    public function getUserRootPassword(bool $escape = true): string
    {
        $password = getenv(self::USER_ROOT_PASSWORD);

        return $escape ? escapeshellarg($password) : $password;
    }

    /**
     * @return string
     */
    public function getDatabaseHost(): string
    {
        return (string) getenv(self::DATABASE_HOST);
    }
    /**
     * @return string
     */
    public function getDatabasePort(): string
    {
        return (string) getenv(self::DATABASE_PORT);
    }
    /**
     * @return string
     */
    public function getDatabaseUser(): string
    {
        return (string) getenv(self::DATABASE_USER);
    }
    /**
     * @return string
     */
    public function getDatabasePassword(): string
    {
        return (string) getenv(self::DATABASE_PASSWORD);
    }

    /**
     * Validate environment variables on startup
     */
    private function validateEnv(): void
    {
        if (!$this->validateIsWritableDir($this->getProjectsRootDir())) {
            throw new \RuntimeException('Env variable PROJECTS_ROOT_DIR does not exist or folder is not writable!');
        }

        if (!$this->validateIsWritableDir($this->getSslCertificatesDir())) {
            throw new \RuntimeException('Env variable SSL_CERTIFICATES_DIR does not exist or folder is not writable!');
        }

        if (!$this->getUserRootPassword(false)) {
            throw new \RuntimeException('USER_ROOT_PASSWORD is not valid!');
        }

        // @TODO: move executing external commands to the separate service, use it to test root password
        $exitCode = 0;

        passthru("echo {$this->getUserRootPassword()} | sudo -S echo \$USER > /dev/null", $exitCode);

        if ($exitCode) {
            throw new \RuntimeException('Root password is not correct. Please, check configuration in ".env.local"');
        }
    }

    /**
     * @param string $dir
     * @return bool
     */
    private function validateIsWritableDir(string $dir): bool
    {
        return $dir && is_writable($dir);
    }
}
