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
use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface;

/**
 * Add domains to the `/etc/hosts` file
 */
class Hosts implements ModifierInterface
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Validation\Domain $domainValidator
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Validation\Domain $domainValidator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $allDomains = [];
        $secureDomains = [];
        $insecureDomains = [];
        $fullYaml = array_merge_recursive(
            $modificationContext->getCompositionYaml(),
            $modificationContext->getDevToolsYaml(),
        );

        // Find all containers with TLS enabled
        foreach ($fullYaml['services'] as $service) {
            if (!isset($service['labels'])) {
                continue;
            }

            foreach ($service['labels'] as $label) {
                if (str_contains($label, 'http.rule=Host') || str_contains($label, 'https.rule=Host')) {
                    $domains = explode(',', rtrim(explode('Host(', $label)[1], ')'));
                    $domains = array_map(static fn ($value) => trim($value, '`'), $domains);
                    $allDomains[] = $domains;

                    if (str_contains($label, 'http.rule=Host')) {
                        $insecureDomains[] = $domains;
                    } else {
                        $secureDomains[] = $domains;
                    }
                }
            }
        }

        $allDomains = array_unique(array_merge(...$allDomains));

        if (empty($allDomains)) {
            return;
        }

        $secureDomains = array_unique(array_merge(...$secureDomains));
        $insecureDomains = array_diff(array_unique(array_merge(...$insecureDomains)), $secureDomains);

        if ($domainsToAdd = array_diff($allDomains, $this->getExistingLocalhostDomains())) {
            $command = is_writable('/etc/hosts') ? 'tee -a /etc/hosts' : 'sudo tee -a /etc/hosts';
            $this->shell->mustRun($command, null, [], PHP_EOL . '127.0.0.1 ' . implode(' ', $domainsToAdd));
        }

        $inlineDomains = '127.0.0.1 ' . implode(' ', $allDomains);
        $domainsAsList = array_reduce($secureDomains, static function ($carry, $domain) {
            return $carry . "- [https://$domain](https://$domain) \n";
        });
        $domainsAsList .= array_reduce($insecureDomains, static function ($carry, $domain) {
            return $carry . "- [http://$domain](http://$domain) \n";
        });
        $domainsAsList = trim($domainsAsList);

        $readmeMd = <<<README
        ## Local development - domains ##

        Add the following domains to the `/etc/hosts` file:

        ```shell
        $inlineDomains
        ```

        Urls list:
        $domainsAsList
        README;

        $modificationContext->appendReadme($this->getSortOrder(), $readmeMd);
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 400;
    }

    /**
     * @return string[]
     */
    private function getExistingLocalhostDomains(): array
    {
        $existingDomains = [];

        foreach (explode(PHP_EOL, $this->filesystem->fileGetContents('/etc/hosts')) as $hostsLine) {
            if (!str_contains($hostsLine, '127.0.0.1')) {
                continue;
            }

            foreach (explode(' ', $hostsLine) as $string) {
                $string = trim($string);

                if ($this->domainValidator->isValid($string)) {
                    $existingDomains[] = $string;
                }
            }
        }

        return $existingDomains;
    }
}
