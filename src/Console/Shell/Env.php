<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Shell;

class Env
{
    private const SSL_CERTIFICATES_DIR = 'SSL_CERTIFICATES_DIR';

    /**
     * @return string
     */
    public function getSslCertificatesDir(): string
    {
        return rtrim($this->getEnv(self::SSL_CERTIFICATES_DIR), '\\/') . DIRECTORY_SEPARATOR;
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
