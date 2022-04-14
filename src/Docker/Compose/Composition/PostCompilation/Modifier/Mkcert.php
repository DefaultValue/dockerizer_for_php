<?php

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
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Console\Shell\Env $env
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Console\Shell\Env $env
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

        Generate self-signed certificates before running this composition in then `$SSL_CERTIFICATES_DIR`:

        ```

        MARKUP;

        foreach ($containersThatRequiteCertificates as $containerName => $domains) {
            $process = $this->generateCertificate($containerName, $domains);

            if ($process->isSuccessful()) {
                $readmeMd .= $process->getCommandLine() . "\n";
            } else {
                throw new \RuntimeException('mkcert: error generating SSL certificates');
            }
        }

        $readmeMd .= "```";
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
