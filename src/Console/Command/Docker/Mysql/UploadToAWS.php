<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use Aws\S3\S3Client;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @TODO: Implement AWS bucket ACL
 * @TODO: Pass bucket region and name a parameter
 * @TODO: Implement AWS bucket region and name as environment variables
 * @TODO: Use `IAM Identity Center` to create IAM used with temporary access key and secret, one per real user?
 * @TODO: Deal with multiple environments from Dockerizer and run martix job?
 * @TODO: Check remote, work only with SSH repositories
 *
 * @noinspection PhpUnused
 */
class UploadToAWS extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:upload-to-aws';

    private const AWS_KEY = 'AWS_KEY';
    private const AWS_SECRET = 'AWS_SECRET';
    private const AWS_DEFAULT_REGION = 'AWS_DEFAULT_REGION';
    private const AWS_DEFAULT_BUCKET_NAME = 'AWS_DEFAULT_BUCKET_NAME';

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        string $name = null
    ) {
        parent::__construct($name);


    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('Uploads database dump to AWS S3')
            ->setHelp(<<<'EOF'
                Uploads database dump to AWS S3 and build a Docker container with this dump.
                Command requires Docker container name in order to create a container medata file.
                This file is then used to run the same DB container, import dump, commit and push image to registry.
                
                    <info>php %command.full_name% ./path/to/db.sql.gz</info>
                EOF)
            ->addArgument(
                'db-dump-path',
                InputArgument::OPTIONAL,
                'Path to the database dump'
            )
            ->addArgument(
                's3-bucket',
                InputArgument::OPTIONAL,
                'S3 bucket name'
            )
            ->addArgument(
                's3-path',
                InputArgument::OPTIONAL,
                'S3 path to upload the database dump to'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // We need to know:
        // 1. Docker container name to collect metadata like MySQL version, env variables, `my.cnf`, etc.
        // 2. S3 bucket name
        // 3. S3 path based on the current repository name?
        // 4. Database type and name?

        // We can get MariaDB database type from Labels:
        // "MARIADB_MAJOR=10.4",
        // "MARIADB_VERSION=1:10.4.27+maria~ubu2004"

        // MySQL > Config.Env:
        // "MYSQL_MAJOR=5.6",
        // "MYSQL_VERSION=5.6.51-1debian9"

        // Bitnami - need to check...

        // Pass directly to the client so that these values are not visible during the debug
//        $awsKey = $this->env->getEnv(self::AWS_KEY);
//        $awsSecret = $this->env->getEnv(self::AWS_SECRET);
        $region = $this->env->getEnv(self::AWS_DEFAULT_REGION);
        $bucketName = $this->env->getEnv(self::AWS_DEFAULT_BUCKET_NAME);

        $s3Client = new S3Client([
            'region'  => $region,
            'version' => 'latest',
            'credentials' => [
                'key'    => $this->env->getEnv(self::AWS_KEY),
                'secret' => $this->env->getEnv(self::AWS_SECRET),
            ]
        ]);

        // Send a PutObject request and get the result object.
        $result = $s3Client->putObject([
            'Bucket'     => $bucketName,
            'Key'        => 'test.sql.gz',
            'SourceFile' => ''
        ]);

        if ($objectURl = $result->get('ObjectURL')) {
            $this->output->writeln('Object URL: ' . $objectURl);

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
