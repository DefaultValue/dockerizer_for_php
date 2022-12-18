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
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
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

        if ($containersThatRequiteCertificates) {
            // Generate certificates and populate Readme
            // phpcs:disable Generic.Files.LineLength.TooLong
            $readmeMd = <<<'MARKUP'
                ## Local development without Traefik reverse-proxy ##

                1. Ensure that ports 80 and 443 are not used by other applications
                2. Add ports mapping to the Apache or Nginx container that acts as an entry point (probably this is the first container in the composition):
                ```
                ports:
                  - "80:80"
                  - "443:443"
                ```
                3. If you do not have an `$SSL_CERTIFICATES_DIR` environment variable (try `echo $SSL_CERTIFICATES_DIR` in the terminal) then replace `${SSL_CERTIFICATES_DIR}` with the path to the directory containing self-signed SSL certificates.
                4. Generate certificates with the `mkcert` command as described in this Readme.

                MARKUP;
            // phpcs:enable

            $modificationContext->appendReadme($this->getSortOrder(), $readmeMd);
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
