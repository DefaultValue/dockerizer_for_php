<?php

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
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Shell $shell
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
        $secureDomains = array_unique(array_merge(...$secureDomains));
        $insecureDomains = array_diff(array_unique(array_merge(...$insecureDomains)), $secureDomains);

        if ($domainsToAdd = array_diff($allDomains, $this->getExistingDomains())) {
            // @TODO: show message in case file is not writeable
            $this->shell->mustRun('tee -a /etc/hosts', null, [], '127.0.0.1 ' . implode(' ', $domainsToAdd) . "\n");
        }

        $inlineDomains = '127.0.0.1 ' . implode(' ', $allDomains);
        $domainsAsList = array_reduce($secureDomains, static function ($carry, $domain) {
            return $carry .= "- [https://$domain](https://$domain) \n";
        });
        $domainsAsList .= array_reduce($insecureDomains, static function ($carry, $domain) {
            return $carry .= "- [http://$domain](http://$domain) \n";
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
     * @return array
     */
    private function getExistingDomains(): array
    {
        $hostsFileHandle = fopen('/etc/hosts', 'rb');
        $existingDomains = [];

        while ($line = fgets($hostsFileHandle)) {
            if (!str_contains($line, '127.0.0.1')) {
                continue;
            }

            foreach (explode(' ', $line) as $string) {
                $string = trim($string);

                if ($this->isValidDomain($string)) {
                    $existingDomains[] = $string;
                }
            }
        }

        fclose($hostsFileHandle);

        return $existingDomains;
    }

    /**
     * Validate domain name. Not used anywhere else for now, thus will keep it here
     *
     * @param string $string
     * @return bool
     */
    private function isValidDomain(string $string): bool
    {
        return filter_var($string, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && preg_match('@\.(.*[A-Za-z])@', $string);
    }
}
