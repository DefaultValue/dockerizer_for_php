<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\AWS\S3;

/**
 * @FUTURE: Replace with Enum when system requirements change to PHP 8.1+
 */
class Environment
{
    public const AWS_KEY = 'AWS_KEY';
    public const AWS_SECRET = 'AWS_SECRET';
    public const AWS_S3_REGION = 'AWS_S3_REGION';
    public const AWS_S3_BUCKET = 'AWS_S3_BUCKET';

    /**
     * @return array<string, string>
     */
    public static function cases(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
