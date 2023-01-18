<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\AWS;

use Aws\S3\S3Client;
use DefaultValue\Dockerizer\AWS\S3\Environment;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class S3
{
    private S3Client $client;

    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
    ) {
    }

    /**
     * @param string $region
     * @return S3Client
     */
    public function getClient(string $region = ''): S3Client
    {
        $this->client ??= new S3Client([
            'region'  => $region ?: $this->env->getEnv(Environment::ENV_AWS_S3_REGION),
            'version' => 'latest',
            'credentials' => [
                'key'    => $this->env->getEnv(Environment::ENV_AWS_KEY),
                'secret' => $this->env->getEnv(Environment::ENV_AWS_SECRET),
            ]
        ]);

        return $this->client;
    }

    /**
     * @param string $bucket
     * @param string $key
     * @param string $sourceFile
     * @param string $body
     * @return void
     */
    public function upload(string $bucket, string $key, string $sourceFile = '', string $body = ''): void
    {
        if (!$sourceFile && !$body) {
            throw new \InvalidArgumentException('Neither source file nor body supplied');
        }

        if ($sourceFile && $body) {
            throw new \InvalidArgumentException('Must provide either source file or body');
        }

        if ($sourceFile && !$this->filesystem->isFile($sourceFile)) {
            throw new FileNotFoundException(null, 0, null, $sourceFile);
        }

        $data = [
            'Bucket' => $bucket,
            'Key' => $key
        ];

        if ($sourceFile) {
            $data['SourceFile'] = $sourceFile;
        } else {
            $data['Body'] = $body;
        }

        // Send a PutObject request and get the result object.
        $result = $this->getClient()->putObject($data);
        $result->get('ObjectURL') ?: throw new \RuntimeException('Unable to upload a database dump to AWS');
    }
}
