<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

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
        $containersThatRequiteCertificates = $this->getContainersThatRequireSslCertificates($modificationContext);

        if (!$containersThatRequiteCertificates) {
            return;
        }

        $traefikRulesFile = $this->env->getTraefikSslConfigurationFile();
        $traefikRules = $this->filesystem->fileGetContents($traefikRulesFile);

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

        // Generate certificates and populate Readme
        // phpcs:disable Generic.Files.LineLength.TooLong
        $readmeMd = <<<'MARKUP'
            ## Local development without Traefik reverse-proxy ##

            If you do not have an `$DOCKERIZER_SSL_CERTIFICATES_DIR` environment variable (try `echo $DOCKERIZER_SSL_CERTIFICATES_DIR` in the terminal) then replace `${DOCKERIZER_SSL_CERTIFICATES_DIR}` with the path to the directory containing self-signed SSL certificates.
            Generate certificates with the `mkcert` command as described in this Readme.
            Here `SSL termination web server` - probably the first Apache or Nginx container in the composition, the one that handles SSL.

            ### Approach 1: Access container by IP ###

            1. Find the SSL termination web server IP address from, for example, `docker container inspect --format '{{json .NetworkSettings.Networks}}' <container_name> | jq`.
            2. Add domains and this IP (instead of `127.0.0.1`) to your `/etc/hosts` file.

            Remember that the IP address may change after the container restart or OS restart.

            ### Approach 2: Publish ports ###

            1. Ensure that ports 80 and 443 are not used by other applications
            2. Add ports mapping to the SSL termination web server:
            ```
            ports:
              - "80:80"
              - "443:443"
            ```

            MARKUP;
        // phpcs:enable

        $modificationContext->appendReadme($this->getSortOrder(), $readmeMd);
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 200;
    }
}
