<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata;

/**
 * @TODO: Replace with Enum when system requirements change to PHP 8.1+
 */
class MetadataKeys
{
    public const DB_IMAGE = 'db_image';
    public const ENVIRONMENT = 'environment';
    public const MY_CNF = 'my_cnf';
    public const CONTAINER_REGISTRY = 'target_repository';

    /**
     * @return array<string, string>
     */
    public static function cases(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
