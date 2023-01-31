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
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;

/**
 * Escape MySQL or MariaDB password.
 *
 * @noinspection PhpUnused
 */
class MysqlPasswordEscaper extends AbstractSslAwareModifier implements
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
                    in_array($key, [
                        Mysql::MYSQL_ROOT_PASSWORD,
                        Mysql::MYSQL_PASSWORD,
                        Mysql::MARIADB_ROOT_PASSWORD,
                        Mysql::MARIADB_PASSWORD,
                        Mysql::PMA_PASSWORD
                    ], true)
                    || (
                        is_string($value)
                        && (
                            str_starts_with($value, Mysql::MYSQL_ROOT_PASSWORD)
                            || str_starts_with($value, Mysql::MYSQL_PASSWORD)
                            || str_starts_with($value, Mysql::MARIADB_ROOT_PASSWORD)
                            || str_starts_with($value, Mysql::MARIADB_PASSWORD)
                            || str_starts_with($value, Mysql::PMA_PASSWORD)
                        )
                    )
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
