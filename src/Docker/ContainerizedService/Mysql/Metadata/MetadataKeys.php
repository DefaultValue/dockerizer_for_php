<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata;

/**
 * @TODO: Replace with Enum when system requirements change to PHP 8.1+
 */
class MetadataKeys
{
    // Docker database image to run. This is a source image for MySQL, MariaDB, etc.
    public const VENDOR_IMAGE = 'vendor_image';

    // All environment variables from the database container - keep it the same as user's container
    public const ENVIRONMENT = 'environment';

    // Where to mount my.cnf
    public const MY_CNF_MOUNT_DESTINATION = 'my_cnf_mount_destination';

    // The content for 'my.cnf'. It MUST contain 'datadir', so it's added automatically if missed from the original file
    public const MY_CNF = 'my_cnf';

    // Docker image name including Docker registry domain if needed. This is how we know where to push the result
    public const TARGET_IMAGE = 'target_image';

    /**
     * @return array<string, string>
     */
    public static function cases(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
