<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\AWS\S3\Environment;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata as MysqlMetadata;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;
use DefaultValue\Dockerizer\Shell\Shell;
use GuzzleHttp\Psr7\Stream;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconstruct Docker DB image from the metadata file and DB dump.
 * Be sure to consume metadata here instead of adding complex logic to this class.
 * It should be possible to read and modify JSON instead of having some magic in this class without the ability to
 * change or extend it.
 *
 * @noinspection PhpUnused
 */
class ReconstructDb extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:reconstruct-db';

    /**
     * 1 - Docker container name,
     * 2 - Where to mount `my.cnf`,
     * 3 - Docker environment variables and other parameters,
     * 4 - Image name
     */
    public const DOCKER_RUN_MYSQL
        = 'docker run --name %s -it -v %s/my.cnf:%s:ro -v %s/mysql_initdb:/docker-entrypoint-initdb.d:ro %s -d %s';

    // Must be passed in the request that triggers a CI/CD job
    private const AWS_S3_OBJECT_KEY = 'AWS_S3_OBJECT_KEY';

    // Name of the database to be placed in `./var/tmp/`. This DB is used to run test with `docker:mysql:test-metadata`
    private const DATABASE_DUMP_FILE = 'database.sql.gz';

    /**
     * A list of Docker images to remove during shutdown. Required to keep the system clean from trash.
     *
     * @var string[] $imagesToRemove
     */
    private array $imagesToRemove = [];

    private bool $testMode = false;

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param \DefaultValue\Dockerizer\AWS\S3 $awsS3
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
        private \DefaultValue\Dockerizer\AWS\S3 $awsS3,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        parent::configure();

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setHelp(<<<'EOF'
            Reconstruct a docker-compose for DB from the metadata file. Used by the CI/CD to build Docker image with the database.

                <info>php %command.full_name% <container></info>
            EOF)
            ->addOption(
                'metadata',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Metadata file (for test only)'
            )
            ->addOption(
                'test-mode',
                '',
                InputOption::VALUE_OPTIONAL,
                'Testing only: use local DB dump \'./var/tmp/database.sql.gz\', don\'t push image to registry',
                false
            );
        // phpcs:enable
    }

    /**
     * @inheritDoc
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->testMode = $input->getOption('test-mode');

        $output->writeln('Checking that AWS access parameters exist...');
        $this->validateAwsEnvParametersPresent();

        $output->writeln('Downloading database metadata file...');
        $metadata = $this->downloadMetadata($input);
        $dockerContainerName = uniqid('mysql-', true);

        $output->writeln('Prepare directory and files for Docker run...');
        $dockerRunDir = $this->prepareForDockerRun($metadata, $dockerContainerName);
        chdir($dockerRunDir);

        // Clean up everything on shutdown
        register_shutdown_function([$this, 'cleanUp'], $output, $dockerRunDir, $dockerContainerName);

        $output->writeln('Dry run the container without a DB to ensure it can be started...');
        $this->dockerRun($metadata, $dockerContainerName, $dockerRunDir);
        $this->shell->mustRun(sprintf('docker rm -f %s', escapeshellarg($dockerContainerName)));

        $output->writeln('Downloading database dump from AWS S3...');
        $mysqlDumpPath = $this->downloadDatabase($dockerRunDir);

        $output->writeln('Starting DB and importing the database...');
        $this->dockerRun($metadata, $dockerContainerName, $dockerRunDir);
        $this->mysql->initialize($dockerContainerName, '', Shell::EXECUTION_TIMEOUT_VERY_LONG);

        // @TODO: Should we add both version and `:latest` tags?
        $output->writeln('Committing new image...');
        $targetImage = $metadata->getTargetImage() . ':latest';
        $this->shell->mustRun(sprintf('docker commit %s %s', $dockerContainerName, $targetImage));

        $output->writeln('Stop running container...');
        $this->shell->mustRun(sprintf('docker rm -f %s', escapeshellarg($dockerContainerName)));
        $this->filesystem->remove($mysqlDumpPath);

        $output->writeln('Restarting a container from a committed image...');
        $metadataForImageTest = $metadata->toArray();
        $metadataForImageTest[MysqlMetadataKeys::VENDOR_IMAGE] = $targetImage;
        $this->dockerRun($this->mysqlMetadata->fromArray($metadataForImageTest), $dockerContainerName, $dockerRunDir);

        $output->writeln('Check that tables are present in the database...');
        // Big Db may take a long time to start on a slow server
        $mysql = $this->mysql->initialize($dockerContainerName, '', Shell::EXECUTION_TIMEOUT_LONG);
        $statement = $mysql->prepareAndExecute('SHOW TABLES;');
        $result = $statement->fetchAll(\PDO::FETCH_ASSOC);

        // @TODO: count a number of tables, views, stored procedures and other things
        // This may be optional, but will help to ensure that the DB image is fully functional
        if (empty($result)) {
            throw new \InvalidArgumentException(
                'DB does not contain tables! Ensure that MySQL `datadir` is set in your `my.cnf` file!'
            );
        }

        if ($this->testMode) {
            return self::SUCCESS;
        }

        $output->writeln('Pushing image to registry...');
        // Do in the pipeline `docker login` before running Dockerizer
        $this->shell->mustRun('docker push ' . escapeshellarg($targetImage));
        $output->writeln('Completed generating DB image with database!');

        return self::SUCCESS;
    }

    /**
     * @return void
     */
    private function validateAwsEnvParametersPresent(): void
    {
        foreach (Environment::cases() as $case) {
            $this->env->getEnv($case);
        }
    }

    /**
     * @param InputInterface $input
     * @return MysqlMetadata
     * @throws \JsonException
     */
    private function downloadMetadata(InputInterface $input): MysqlMetadata
    {
        $metadata = (string) $input->getOption('metadata');

        if (!$metadata) {
            /** @var Stream $stream */
            $stream = $this->awsS3->getClient($this->env->getEnv(Environment::AWS_S3_REGION))
                ->getObject([
                    'Bucket' => $this->env->getEnv(Environment::AWS_S3_BUCKET),
                    'Key' => $this->env->getEnv(self::AWS_S3_OBJECT_KEY),
                ])->get('Body');
            $metadata = $stream->getContents();
        }

        return $this->mysqlMetadata->fromJson($metadata);
    }

    /**
     * @param MysqlMetadata $metadata
     * @param string $containerName
     * @return string
     */
    public function prepareForDockerRun(MysqlMetadata $metadata, string $containerName): string
    {
        // Here we store
        $path = $this->filesystem->mkTmpDir($containerName);
        $this->filesystem->mkTmpDir($containerName . DIRECTORY_SEPARATOR . 'mysql_initdb');
        $myCnf = $path . DIRECTORY_SEPARATOR . 'my.cnf';
        $this->filesystem->filePutContents($myCnf, $metadata->getMyCnf());

        return $path;
    }

    /**
     * @param MysqlMetadata $metadata
     * @param string $dockerContainerName
     * @param string $dockerRunDir
     * @return void
     */
    private function dockerRun(
        MysqlMetadata $metadata,
        string $dockerContainerName,
        string $dockerRunDir
    ): void {
        $vendorImage = $metadata->getVendorImage();
        $environment = '';

        if ($metadata->getEnvironment()) {
            $environment .= '-e ' . implode(
                ' -e ',
                array_map('escapeshellarg', $metadata->getEnvironment())
            );
        }

        $command = sprintf(
            self::DOCKER_RUN_MYSQL,
            $dockerContainerName,
            $dockerRunDir,
            $metadata->getMyCnfMountDestination(),
            $dockerRunDir,
            $environment,
            $vendorImage
        );

        $this->shell->mustRun($command);
        $this->registerImageForCleanup($vendorImage);
    }

    /**
     * @param string $dockerRunDir
     * @return string
     */
    private function downloadDatabase(string $dockerRunDir): string
    {
        $mysqlDumpPath = implode(DIRECTORY_SEPARATOR, [$dockerRunDir, 'mysql_initdb', self::DATABASE_DUMP_FILE]);

        // For testing we do not need to create a big DB. It's fine to take a Magento DB dump and test with it
        // The file should be ./var/tmp/database.sql.gz
        if ($this->testMode) {
            $this->filesystem->copy(
                dirname($dockerRunDir) . DIRECTORY_SEPARATOR . self::DATABASE_DUMP_FILE,
                $mysqlDumpPath
            );

            return $mysqlDumpPath;
        }

        $this->awsS3->getClient($this->env->getEnv(Environment::AWS_S3_REGION))
            ->getObject([
                'Bucket' => $this->env->getEnv(Environment::AWS_S3_BUCKET),
                'Key' => str_replace('.json', '.sql.gz', $this->env->getEnv(self::AWS_S3_OBJECT_KEY)),
                '@http' => [
                    'sink' => $mysqlDumpPath
                ]
            ]);

        return $mysqlDumpPath;
    }

    /**
     * @param string $imageName
     * @return void
     */
    private function registerImageForCleanup(string $imageName): void
    {
        $this->imagesToRemove[] = $imageName;

        // Remove both `mysql:8.0` and `mysql:8.0.24`
        if (
            str_starts_with($imageName, GenerateMetadata::SUPPORTED_DB_IMAGE_MYSQL)
            || str_starts_with($imageName, GenerateMetadata::SUPPORTED_DB_IMAGE_MARIADB)
            || str_starts_with($imageName, GenerateMetadata::SUPPORTED_DB_IMAGE_BITNAMI_MARIADB)
        ) {
            $this->imagesToRemove[] = substr($imageName, 0, (int) strrpos($imageName, '.'));
        }
    }

    /**
     * @param OutputInterface $output
     * @param string $dockerRunDir
     * @param string $dockerContainerName
     * @return void
     */
    private function cleanUp(OutputInterface $output, string $dockerRunDir, string $dockerContainerName): void
    {
        $output->writeln('Cleaning up build artifacts...');
        $this->shell->run(sprintf('docker rm -f %s', escapeshellarg($dockerContainerName)), $dockerRunDir);

        foreach (array_unique($this->imagesToRemove) as $imageName) {
            $this->shell->run(sprintf('docker image rm %s', escapeshellarg($imageName)), $dockerRunDir);
        }

        if (is_dir($dockerRunDir)) {
            $this->filesystem->remove($dockerRunDir);
        }

        $output->writeln('Cleanup completed!');
    }
}
