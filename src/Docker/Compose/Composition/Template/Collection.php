<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Template;

/**
 * Collection of Docker composition templates from ./templates/services/ or from the repositories
 */
class Collection extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFileCollection
{
    public const PROCESSIBLE_FILE_INSTANCE = \DefaultValue\Dockerizer\Docker\Compose\Composition\Template::class;
}
