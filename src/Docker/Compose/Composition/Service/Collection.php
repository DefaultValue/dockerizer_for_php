<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

/**
 * Collection of service definitions from ./templates/services/ or from the repositories
 */
class Collection extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFileCollection
{
    public const PROCESSABLE_FILE_INSTANCE = \DefaultValue\Dockerizer\Docker\Compose\Composition\Service::class;
}
