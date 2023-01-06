<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\AWS\S3\Environment;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Container as CommandOptionContainer;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata as MysqlMetadata;
use DefaultValue\Dockerizer\Shell\EnvironmentVariableMissedException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
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
class UploadToAWS extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    use \DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait\TargetImage;

    protected static $defaultName = 'docker:mysql:upload-to-aws';

    private const ENV_AWS_S3_BUCKET_PREFIX = 'DOCKERIZER_AWS_S3_BUCKET_PREFIX';

    protected array $commandSpecificOptions = [
        CommandOptionContainer::OPTION_NAME,
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Validation\Domain $domainValidator
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\AWS\S3 $awsS3
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Validation\Domain $domainValidator,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\AWS\S3 $awsS3,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('Uploads database dump and metadata file to AWS S3')
            // phpcs:disable Generic.Files.LineLength.TooLong
            ->setHelp(sprintf(
                <<<'EOF'
                This command requires Docker container name to create a MySQL metadata file. This file is then used to run the same DB container, import dump, commit and push image to a registry.

                Create dump from a running Docker container, upload to AWS S3:
                    <info>php %%command.full_name%% -c <container> -d</info>

                Push existing dump upload to AWS S3:
                    <info>php %%command.full_name%% -c <container> -d <dump.sql.gz></info>

                Explicitly pass AWS S3 Bucket:
                    <info>php %%command.full_name%% -c <container> -d -b <bucket></info>

                To be implemented. Use existing metadata file (local or from AWS):
                    <info>php %%command.full_name%% -d <dump.sql.gz> -m <metadata.json></info>

                For now, key, secret, and region are passed as environment variables:
                - %s
                - %s
                - %s
                See \DefaultValue\Dockerizer\AWS\S3
                EOF,
                Environment::ENV_AWS_KEY,
                Environment::ENV_AWS_SECRET,
                Environment::ENV_AWS_S3_REGION
            ))
            ->addOption(
                'dump',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Path to existing dump. It must be a <info>\'.gz\'</info> archive. Leave empty to create dump from a running Docker container.',
                ''
            )
            ->addOption(
                'bucket',
                'b',
                InputOption::VALUE_OPTIONAL,
                'AWS S3 Bucket to upload data. Ignores environment variable DOCKERIZER_AWS_S3_BUCKET_PREFIX.'
            )
            ->addOption(
                'metadata',
                'm',
                InputOption::VALUE_OPTIONAL,
                'To de implemented. Path to local \'metadata.json\' or its AWS S3 object URL. Useful for updating dumps if there is no running Docker container for them.'
            )
            ->addOption(
                'target-image',
                't',
                InputOption::VALUE_OPTIONAL,
                'Docker image name including registry domain (if needed) and excluding tags'
            );
            // phpcs:enable
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
        // Validate information about DB dump so that we do not move further
        // @TODO: to be implemented
        if ($input->getOption('metadata')) {
            throw new \InvalidArgumentException('Using the option \'--metadata\' isn\'t yet implemented');
        }

        $dbDumpHostPath = (string) $input->getOption('dump');
        $container = $input->getOption(CommandOptionContainer::OPTION_NAME);

        if (!$dbDumpHostPath && !$container) {
            throw new \InvalidArgumentException(
                'Explicitly pass dump path via the \'--dump\' option' .
                ' or add \'--container\' to automatically create a compressed database dump'
            );
        }

        if ($dbDumpHostPath && !$this->filesystem->isFile($dbDumpHostPath)) {
            throw new FileNotFoundException(null, 0, null, $dbDumpHostPath);
        }

        // Do the stuff
        if ($container) {
            $mysql = $this->mysql->initialize($container);
            $metadata = $this->generateMetadataFromContainer(
                $input,
                $this->getTargetImage($input, $output, $mysql, $this->getHelper('question'))
            );
        } else {
            $metadata = $this->getMetadata($input, $output);
        }

        $output->writeln('DB metadata:');
        $output->writeln($metadata->toJson());

        // Dump database only after generating metadata to save time. Dumping a big DB may take significant time.
        $dbDumpHostPath = $this->getDump($output, $container, $dbDumpHostPath);

        $imageNameParts = explode('/', $metadata->getTargetImage());
        // Check if the first part of the image name is a valid domain. Images from DockerHub don't contain this part
        $registryDomain = str_contains($imageNameParts[0], ':')
            ? substr($imageNameParts[0], 0, (int)strpos($imageNameParts[0], ':'))
            : $imageNameParts[0];

        if (
            $registryDomain === 'localhost'
            || filter_var($registryDomain, FILTER_VALIDATE_IP)
            || $this->domainValidator->isValid($registryDomain)
        ) {
            array_shift($imageNameParts);
        }

        $metadataAwsPath = implode('/', $imageNameParts);
        $bucketName = $this->getAwsS3Bucket($input, $imageNameParts[0]);

        $output->writeln(sprintf(
            'Uploading the file <info>%s</info> to the bucket <info>%s</info> as <info>%s.sql.gz</info>',
            $dbDumpHostPath,
            $bucketName,
            $metadataAwsPath
        ));
        $this->awsS3->upload($bucketName, $metadataAwsPath . '.sql.gz', $dbDumpHostPath);
        $output->writeln(sprintf(
            'Uploading metadata to the bucket <info>%s</info> as <info>%s.json</info>',
            $bucketName,
            $metadataAwsPath
        ));
        $this->awsS3->upload($bucketName, $metadataAwsPath . '.json', '', $metadata->toJson());
        $output->writeln('Upload completed');

        return self::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return MysqlMetadata
     */
    private function getMetadata(InputInterface $input, OutputInterface $output): MysqlMetadata
    {
        // Try generating metadata before creating a dump. Dumping a DB may take a lot of time, but may not be needed
        // if user decides to interrupt the process
        throw new \InvalidArgumentException('Using the option \'--metadata\' isn\'t yet implemented');
    }

    /**
     * @param InputInterface $originalInput
     * @param string $targetImage
     * @return MysqlMetadata
     * @throws ExceptionInterface
     * @throws \JsonException
     */
    private function generateMetadataFromContainer(
        InputInterface $originalInput,
        string $targetImage
    ): MysqlMetadata {
        $mysqlContainerName = $originalInput->getOption(CommandOptionContainer::OPTION_NAME);
        $metadataCommand = $this->getApplication()?->find('docker:mysql:generate-metadata')
            ?? throw new \LogicException('Application is not initialized');
        $inputParameters = [
            'command' => 'docker:mysql:generate-metadata',
            GenerateMetadata::COMMAND_ARGUMENT_CONTAINER => $mysqlContainerName,
            '--target-image' => $targetImage
        ];

        $input = new ArrayInput($inputParameters);
        $input->setInteractive($originalInput->isInteractive());
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $metadataCommand->run($input, $output);

        return $this->mysqlMetadata->fromJson($output->fetch());
    }

    /**
     * @param OutputInterface $output
     * @param string $container
     * @param string $dbDumpHostPath
     * @return string
     */
    private function getDump(OutputInterface $output, string $container, string $dbDumpHostPath): string
    {
        if (!$dbDumpHostPath) {
            $output->writeln('Creating database dump for upload...');
            $mysql = $this->mysql->initialize($container);
            $tempFile = tmpfile() ?: throw new \RuntimeException('Can\'t create a temporary file for DB dump');
            $dbDumpHostPath = stream_get_meta_data($tempFile)['uri'];

            register_shutdown_function(
                function (string $dbDumpHostPath) {
                    if ($this->filesystem->isFile($dbDumpHostPath)) {
                        $this->filesystem->remove($dbDumpHostPath);
                    }
                },
                $dbDumpHostPath
            );
            fclose($tempFile);
            $mysql->dump($dbDumpHostPath);
        }

        if (!$this->filesystem->isFile($dbDumpHostPath)) {
            throw new FileNotFoundException(null, 0, null, $dbDumpHostPath);
        }

        return $dbDumpHostPath;
    }

    /**
     * @param InputInterface $input
     * @param string $firstImageNamePart
     * @return string
     */
    private function getAwsS3Bucket(InputInterface $input, string $firstImageNamePart): string
    {
        // Get from command parameters
        if ($bucket = (string) $input->getOption('bucket')) {
            return $bucket;
        }

        try {
            $bucketPrefix = $this->env->getEnv(self::ENV_AWS_S3_BUCKET_PREFIX);
        } catch (EnvironmentVariableMissedException) {
            $bucketPrefix = '';
        }

        return $bucketPrefix . $firstImageNamePart;
    }
}
