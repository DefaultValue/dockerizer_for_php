<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata;

class MetadataKeys
{
    public const DB_IMAGE = 'db_image';
    public const ENVIRONMENT = 'environment';
    public const MY_CNF = 'my_cnf';
    public const DOCKER_IMAGE = 'target_repository';
}
