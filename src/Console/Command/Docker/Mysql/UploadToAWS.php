<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use Aws\S3\S3Client;
use DefaultValue\Dockerizer\AWS\S3\Environment;
use DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait\GenerateMetadataTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * @TODO: Use `IAM Identity Center` to create IAM used with temporary access key and secret, one per real user?
 * @TODO: Download all metadata files and ask which DBs to update
 *
 * @noinspection PhpUnused
 */
class UploadToAWS extends \Symfony\Component\Console\Command\Command
{
    use GenerateMetadataTrait;

    protected static $defaultName = 'docker:mysql:upload-to-aws';

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Validation\Domain $domainValidator
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Validation\Domain $domainValidator,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
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
                GenerateMetadata::COMMAND_ARGUMENT_CONTAINER,
                InputArgument::REQUIRED,
                'Docker container name'
            )
            ->addArgument(
                'db-dump-path',
                InputArgument::OPTIONAL,
                'Path to the database dump'
            )
            ->addOption(
                'dump-from-container',
                'd',
                InputOption::VALUE_NONE,
                'Create a DB dump from the container'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbDumpHostPath = (string) $input->getArgument('db-dump-path');
        $createDump = $input->getOption('dump-from-container');

        if ($dbDumpHostPath && $createDump) {
            throw new \InvalidArgumentException(
                'Ambiguous parameters: passing DB dump path and requiring to create a DB dump at the same time'
            );
        }

        $metadata = $this->mysqlMetadata->fromJson(
            $this->generateMetadata($input->getArgument(GenerateMetadata::COMMAND_ARGUMENT_CONTAINER))
        );

        if ($createDump) {
            $mysql = $this->mysql->initialize($input->getArgument(GenerateMetadata::COMMAND_ARGUMENT_CONTAINER));
            $tempFile = tmpfile() ?: throw new \RuntimeException('Can\'t create a temporary file for DB dump');
            $dbDumpHostPath = stream_get_meta_data($tempFile)['uri'];
            register_shutdown_function(static fn (string $path) => is_file($path) && unlink($path), $dbDumpHostPath);
            fclose($tempFile);
            $mysql->dump($dbDumpHostPath);
        }

        if (!$dbDumpHostPath) {
            throw new \InvalidArgumentException(
                'Database dump path missed. Either provide a valid path to the `.sql.gz` archive' .
                ' or use `-d` option to create a dump automatically'
            );
        }

        if (!$this->filesystem->isFile($dbDumpHostPath)) {
            throw new FileNotFoundException(null, 0, null, $dbDumpHostPath);
        }

        $imageNameParts = explode('/', $metadata->getTargetImage());
        // Check if the first part of the image name is a valid domain. Images from DockerHub don't contain this part
        $domain = str_contains($imageNameParts[0], ':')
            ? substr($imageNameParts[0], 0, (int) strpos($imageNameParts[0], ':'))
            : $imageNameParts[0];

        if ($this->domainValidator->isValid($domain)) {
            array_shift($imageNameParts);
        }

        $region = $this->env->getEnv(Environment::AWS_S3_REGION);
        $bucketName = array_shift($imageNameParts);
        $metadataAwsPath = implode('/', $imageNameParts);

        $s3Client = new S3Client([
            'region'  => $region,
            'version' => 'latest',
            'credentials' => [
                'key'    => $this->env->getEnv(Environment::AWS_KEY),
                'secret' => $this->env->getEnv(Environment::AWS_SECRET),
            ]
        ]);

        // Send a PutObject request and get the result object.
        $result = $s3Client->putObject([
            'Bucket'     => $bucketName,
            'Key'        => $metadataAwsPath . '.sql.gz',
            'SourceFile' => $dbDumpHostPath
        ]);

        if ($objectURl = $result->get('ObjectURL')) {
            $output->writeln('Database dump URL: ' . $objectURl);
        } else {
            throw new \RuntimeException('Unable to upload a database dump to AWS');
        }

        // Send a PutObject request and get the result object.
        $result = $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key'    => $metadataAwsPath . '.json',
            'Body'   => $metadata->toJson()
        ]);

        if ($objectURl = $result->get('ObjectURL')) {
            $output->writeln('Metadata file URL: ' . $objectURl);
        } else {
            throw new \RuntimeException('Unable to upload metadata file to AWS. Please, try again.');
        }

        return self::SUCCESS;
    }
}
