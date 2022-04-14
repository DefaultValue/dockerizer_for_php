<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Shell;

// @TODO: validate files and dirs on startup
class Env
{
    private const PROJECTS_ROOT_DIR = 'PROJECTS_ROOT_DIR';

    private const SSL_CERTIFICATES_DIR = 'SSL_CERTIFICATES_DIR';

    private const TRAEFIK_SSL_CONFIGURATION_FILE = 'TRAEFIK_SSL_CONFIGURATION_FILE';

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
     * @return string
     */
    public function getTraefikSslConfigurationFile(): string
    {
        return $this->getEnv(self::TRAEFIK_SSL_CONFIGURATION_FILE);
    }

    /**
     * @param string $variable
     * @return string
     */
    private function getEnv(string $variable): string
    {
        $envVariableValue = getenv($variable);

        if ($envVariableValue === false) {
            throw new \RuntimeException("Environment variable $variable is not available");
        }

        return $envVariableValue;
    }
}
