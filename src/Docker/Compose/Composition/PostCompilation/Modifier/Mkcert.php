<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;

/**
 * Generate SSL certificates
 * For now, the convention is that certificate file name = main container name:
 * Container name: container_name: "{{domains|first}}-{{environment}}"
 * Apache virtual host: SSLCertificateFile /certs/{{domains|first}}-{{environment}}.pem
 * Please, follow this convention for now!
 */
class Mkcert implements \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
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
        $containersThatRequiteCertificates = [];
        $fullYaml = array_merge_recursive(
            $modificationContext->getCompositionYaml(),
            $modificationContext->getDevToolsYaml(),
        );

        // Find all containers with TLS enabled
        foreach ($fullYaml['services'] as $service) {
            if (!isset($service['labels'])) {
                continue;
            }

            $tlsEnabled = false;
            $domains = [];

            foreach ($service['labels'] as $label) {
                // @TODO: would br great to handle not only Treafik, but other proxies as well
                if (str_contains($label, '.tls=true')) {
                    $tlsEnabled = true;
                }

                if (str_contains($label, 'https.rule=Host')) {
                    $domains = explode(',', rtrim(explode('Host(', $label)[1], ')'));
                    $domains = array_map(static fn ($value) => trim($value, '`'), $domains);
                }
            }

            if ($tlsEnabled) {
                $containersThatRequiteCertificates[$service['container_name']] = $domains;
            }
        }

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
            $this->generateCertificate($containerName, $domains);
            $readmeMd .= $this->shell->getLastExecutedCommand() . "\n";
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
     * @return void
     */
    private function generateCertificate(string $containerName, array $domains): void
    {
        $command = [
            'mkcert',
            '-cert-file',
            $containerName . '.pem',
            '-key-file',
            $containerName . '-key.pem'
        ];
        $command = array_merge($command, $domains);

        $this->shell->exec($command, $this->env->getSslCertificatesDir());
    }
}
