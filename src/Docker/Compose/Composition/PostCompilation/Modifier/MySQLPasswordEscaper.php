<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;
use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;

/**
 * Escape MySQL or MariaDB password.
 */
class MySQLPasswordEscaper extends AbstractSslAwareModifier implements
    \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $modificationContext->setCompositionYaml(
            $this->escapePasswords($modificationContext->getCompositionYaml())
        );
        $modificationContext->setDevToolsYaml(
            $this->escapePasswords($modificationContext->getDevToolsYaml())
        );
    }

    /**
     * Escape MySQL or MariaDB password for any service.
     *
     * Both MariaDB and MySQL:
     * `$` (dollar sign) must be escaped as `$$` (two dollar signs) so it doesn't get interpreted as variable
     * https://github.com/photoprism/photoprism/discussions/2094
     *
     * Only MySQL:
     * Escape `'` (single quote) as `\'` (backslash and single quote) for entrypoint script to work as expected
     *
     * Only MariaDB:
     * Escape `'` (single quote) as `''` (two single quotes) for entrypoint script to work as expected
     *
     * We do not support single or double quotes, because escaping them is too complicated.
     * This also creates problems because environment variables contain quoted values.
     *
     * @param array $yamlContent
     * @return array
     */
    private function escapePasswords(array $yamlContent): array
    {
        foreach ($yamlContent['services'] as &$service) {
            if (!isset($service['image'], $service['environment'])) {
                continue;
            }

            foreach ($service['environment'] as $key => $value) {
                if (
                    str_starts_with($value, MySQL::MYSQL_PASSWORD)
                    || str_starts_with($value, MySQL::MARIADB_ROOT_PASSWORD)
                    || str_starts_with($value, MySQL::MARIADB_PASSWORD)
                    || in_array($key, [
                        MySQL::MYSQL_PASSWORD,
                        MySQL::MARIADB_ROOT_PASSWORD,
                        MySQL::MARIADB_PASSWORD
                    ], true)
                ) {
                    $value = str_replace('$', '$$', $value);
                    $service['environment'][$key] = $value;
                }
            }
        }

        return $yamlContent;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 900;
    }
}
