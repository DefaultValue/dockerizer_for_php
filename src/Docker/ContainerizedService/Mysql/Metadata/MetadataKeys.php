<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata;

class MetadataKeys
{
    public const DB_TYPE = 'db_type';
    public const DB_VERSION = 'db_version';
    public const ENVIRONMENT = 'environment';
    public const MY_CNF = 'my_cnf';
    public const TARGET_REPOSITORY = 'target_repository';
}
