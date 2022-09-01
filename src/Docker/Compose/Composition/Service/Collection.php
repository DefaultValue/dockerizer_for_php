<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

/**
 * Collection of service definitions from ./templates/services/ or from the repositories
 */
class Collection extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFileCollection
{
    public const PROCESSABLE_FILE_INSTANCE = \DefaultValue\Dockerizer\Docker\Compose\Composition\Service::class;
}
