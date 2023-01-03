<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata as MysqlMetadata;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
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
    protected static $defaultName = 'docker:mysql:upload-to-aws';

    /**
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Validation\Domain $domainValidator
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\AWS\S3 $awsS3
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Validation\Domain $domainValidator,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\AWS\S3 $awsS3,
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

        if ($dbDumpHostPath && !$this->filesystem->isFile($dbDumpHostPath)) {
            throw new FileNotFoundException(null, 0, null, $dbDumpHostPath);
        }

        // Try generating metadata before creating a dump. Dumping a DB may take a lot of time, but may not be needed
        // if user decides to interrupt the process
        $metadata = $this->generateMetadata($input, $output);

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

        $metadataAwsPath = implode('/', $imageNameParts);
        $bucketName = $metadata->getAwsS3Bucket();

        $this->awsS3->upload($bucketName, $metadataAwsPath . '.sql.gz', $dbDumpHostPath);
        $this->awsS3->upload($bucketName, $metadataAwsPath . '.json', '', $metadata->toJson());

        return self::SUCCESS;
    }

    /**
     * @param InputInterface $originalInput
     * @param OutputInterface $originalOutput
     * @return MysqlMetadata
     * @throws \JsonException
     * @throws ExceptionInterface
     */
    private function generateMetadata(InputInterface $originalInput, OutputInterface $originalOutput): MysqlMetadata
    {
        $mysqlContainerName = $originalInput->getArgument(GenerateMetadata::COMMAND_ARGUMENT_CONTAINER);
        $metadataCommand = $this->getApplication()?->find('docker:mysql:generate-metadata')
            ?? throw new \LogicException('Application is not initialized');
        $inputParameters = [
            'command' => 'docker:mysql:generate-metadata',
            GenerateMetadata::COMMAND_ARGUMENT_CONTAINER => $mysqlContainerName,
            '-n' => $originalInput->isInteractive(),
            '-q' => $originalOutput->isQuiet()
        ];

        $input = new ArrayInput($inputParameters);
        $input->setInteractive($originalInput->isInteractive());
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $metadataCommand->run($input, $output);

        return $this->mysqlMetadata->fromJson($output->fetch());
    }
}
