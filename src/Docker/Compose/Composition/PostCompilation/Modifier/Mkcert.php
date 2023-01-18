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
use Symfony\Component\Process\Process;

/**
 * Generate SSL certificates
 * For now, the convention is that certificate file name = main container name:
 * Container name: container_name: "{{domains|first}}-{{environment}}"
 * Apache virtual host: SSLCertificateFile /certs/{{domains|first}}-{{environment}}.pem
 * Please, follow this convention for now!
 */
class Mkcert extends AbstractSslAwareModifier implements
    \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Shell\Env $env
    ) {
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $containersThatRequiteCertificates = $this->getContainersThatRequireSslCertificates($modificationContext);

        if (empty($containersThatRequiteCertificates)) {
            return;
        }

        // Generate certificates and populate Readme
        $readmeMd = <<<'MARKUP'
        ## Local development - self-signed certificates ##

        Generate self-signed certificates before running this composition in the `$SSL_CERTIFICATES_DIR`:

        ```shell

        MARKUP;

        foreach ($containersThatRequiteCertificates as $containerName => $domains) {
            $process = $this->generateCertificate($containerName, $domains);

            if ($process->isSuccessful()) {
                $readmeMd .= $process->getCommandLine() . "\n";
            } else {
                throw new \RuntimeException('mkcert: error generating SSL certificates');
            }
        }

        $readmeMd .= "```\n\nAdd these keys to the Traefik configuration file if needed.";
        $modificationContext->appendReadme($this->getSortOrder(), $readmeMd);
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 300;
    }

    /**
     * @param string $containerName
     * @param array $domains
     * @return Process
     */
    private function generateCertificate(string $containerName, array $domains): Process
    {
        $command = sprintf(
            'mkcert -cert-file=%s.pem -key-file=%s-key.pem %s',
            $containerName,
            $containerName,
            implode(' ', $domains)
        );

        return $this->shell->mustRun($command, $this->env->getSslCertificatesDir());
    }
}
