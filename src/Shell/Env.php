<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Shell;

// @TODO: validate files and dirs on startup
class Env
{
    public const ENV_PROJECTS_ROOT_DIR = 'DOCKERIZER_PROJECTS_ROOT_DIR';
    private const ENV_SSL_CERTIFICATES_DIR = 'DOCKERIZER_SSL_CERTIFICATES_DIR';
    private const ENV_TRAEFIK_SSL_CONFIGURATION_FILE = 'DOCKERIZER_TRAEFIK_SSL_CONFIGURATION_FILE';

    /**
     * @return string
     */
    public function getProjectsRootDir(): string
    {
        return realpath($this->getEnv(self::ENV_PROJECTS_ROOT_DIR)) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getSslCertificatesDir(): string
    {
        return realpath($this->getEnv(self::ENV_SSL_CERTIFICATES_DIR)) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getTraefikSslConfigurationFile(): string
    {
        return $this->getEnv(self::ENV_TRAEFIK_SSL_CONFIGURATION_FILE);
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
