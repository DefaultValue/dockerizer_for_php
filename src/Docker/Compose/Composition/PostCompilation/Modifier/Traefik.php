<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;

/**
 * Currently Traefik is the only supported reverse proxy
 * Traefik configuration is already defined inside the composition
 * It is possible to create services that will work with other proxies
 */
class Traefik extends AbstractSslAwareModifier implements
    \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @param \DefaultValue\Dockerizer\Console\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
    ) {
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $traefikRulesFile = $this->env->getTraefikSslConfigurationFile();
        $traefikRules = $this->filesystem->fileGetContents($traefikRulesFile);
        $containersThatRequiteCertificates = $this->getContainersThatRequireSslCertificates($modificationContext);

        foreach ($containersThatRequiteCertificates as $containerName => $domains) {
            $sslCertificateFile = "$containerName.pem";
            $sslCertificateKeyFile = "$containerName-key.pem";

            if (!str_contains($traefikRules, $sslCertificateFile)) {
                $this->filesystem->filePutContents(
                    $traefikRulesFile,
                    <<<TOML


                  [[tls.certificates]]
                    certFile = "/certs/$sslCertificateFile"
                    keyFile = "/certs/$sslCertificateKeyFile"
                TOML,
                    FILE_APPEND
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 200;
    }
}
