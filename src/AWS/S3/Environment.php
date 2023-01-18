<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\AWS\S3;

/**
 * @TODO: Replace with Enum when system requirements change to PHP 8.1+
 */
class Environment
{
    public const ENV_AWS_KEY = 'DOCKERIZER_AWS_KEY';
    public const ENV_AWS_SECRET = 'DOCKERIZER_AWS_SECRET';
    public const ENV_AWS_S3_REGION = 'DOCKERIZER_AWS_S3_REGION';
    public const ENV_AWS_S3_BUCKET = 'DOCKERIZER_AWS_S3_BUCKET';

    /**
     * @return array<string, string>
     */
    public static function cases(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
