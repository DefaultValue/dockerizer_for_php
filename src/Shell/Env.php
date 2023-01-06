<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Shell;

// @TODO: validate files and dirs on startup
class Env
{
    /**
     * @deprecated
     */
    private const PROJECTS_ROOT_DIR = 'PROJECTS_ROOT_DIR';
    public const ENV_PROJECTS_ROOT_DIR = 'DOCKERIZER_PROJECTS_ROOT_DIR';

    /**
     * @deprecated
     */
    private const SSL_CERTIFICATES_DIR = 'SSL_CERTIFICATES_DIR';
    private const ENV_SSL_CERTIFICATES_DIR = 'DOCKERIZER_SSL_CERTIFICATES_DIR';

    /**
     * @deprecated
     */
    private const TRAEFIK_SSL_CONFIGURATION_FILE = 'TRAEFIK_SSL_CONFIGURATION_FILE';
    private const ENV_TRAEFIK_SSL_CONFIGURATION_FILE = 'DOCKERIZER_TRAEFIK_SSL_CONFIGURATION_FILE';

    /**
     * @return string
     */
    public function getProjectsRootDir(): string
    {
        try {
            $projectsRootDir = $this->getEnv(self::ENV_PROJECTS_ROOT_DIR);
        } catch (EnvironmentVariableMissedException) {
            $projectsRootDir = $this->getEnv(self::PROJECTS_ROOT_DIR);
        }

        return realpath($projectsRootDir) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getSslCertificatesDir(): string
    {
        try {
            $sslCertificatesDir = $this->getEnv(self::ENV_SSL_CERTIFICATES_DIR);
        } catch (EnvironmentVariableMissedException) {
            $sslCertificatesDir = $this->getEnv(self::SSL_CERTIFICATES_DIR);
        }

        return realpath($sslCertificatesDir) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getTraefikSslConfigurationFile(): string
    {
        try {
            return $this->getEnv(self::ENV_TRAEFIK_SSL_CONFIGURATION_FILE);
        } catch (EnvironmentVariableMissedException) {
            return $this->getEnv(self::TRAEFIK_SSL_CONFIGURATION_FILE);
        }
    }

    /**
     * @param string $variable
     * @return string
     */
    public function getEnv(string $variable): string
    {
        $envVariableValue = getenv($variable);

        if ($envVariableValue === false) {
            throw new EnvironmentVariableMissedException("Environment variable $variable is not available");
        }

        return $envVariableValue;
    }
}
