<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata;

class DBType
{
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';
    public const BITNAMI_MARIADB = 'bitnami/mariadb';
}
