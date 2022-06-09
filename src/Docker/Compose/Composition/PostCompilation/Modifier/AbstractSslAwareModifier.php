<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;

abstract class AbstractSslAwareModifier
{
    /**
     * @param ModificationContext $modificationContext
     * @return array
     */
    protected function getContainersThatRequireSslCertificates(ModificationContext $modificationContext): array
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
                // We can't handle containers that do not have `container_name` because composition is not running yet
                $containersThatRequiteCertificates[$service['container_name']] = $domains;
            }
        }

        return $containersThatRequiteCertificates;
    }
}
