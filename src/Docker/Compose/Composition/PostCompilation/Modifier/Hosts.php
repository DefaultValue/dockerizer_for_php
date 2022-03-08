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
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell
    ) {
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $allDomains = [];
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
                if (str_contains($label, 'http.rule=Host')) {
                    $domains = explode(',', rtrim(explode('Host(', $label)[1], ')'));
                    $allDomains[] = array_map(static fn ($value) => trim($value, '`'), $domains);

                    break;
                }
            }
        }

        if ($domainsToAdd = array_diff(array_merge(...$allDomains), $this->getExistingDomains())) {
            $this->shell->exec(
                ['tee', '-a', '/etc/hosts'],
                null,
                [],
                '127.0.0.1 ' . implode(' ', $domainsToAdd) . "\n"
            );
        }

        $inlineDomains = '127.0.0.1 ' . implode(' ', array_merge(...$allDomains));
        $readmeMd = <<<README
        ## Local development - domains ##

        Add the following domains to the `/etc/hosts` file:

        ```
        $inlineDomains
        ```
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
