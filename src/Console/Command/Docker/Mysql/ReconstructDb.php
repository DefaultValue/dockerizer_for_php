<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\AWS\S3\Environment;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
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
    public const CI_CD_ENV_METADATA_FILE_NAME = 'METADATA_FILE_NAME';

    /**
     * A list of Docker images to remove during shutdown. Required to keep the system clean from trash.
     *
     * @var string[] $imagesToRemove
     */
    private array $imagesToRemove = [];

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
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

                <info>php %command.full_name% <container-name></info>
            EOF)
            ->addOption(
                'metadata',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Metadata file (for test only)'
            );
        // phpcs:enable
    }

    /**
     * @inheritDoc
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $output->writeln('Downloading database dump from AWS S3...');
        $this->downloadDatabase($input, $dockerRunDir);
        // run, but with DB dump

        $output->writeln('Generating "docker run" command...');
        $this->shell->mustRun(sprintf('docker rm -f %s', escapeshellarg($dockerContainerName)));
        $myCnfMountTarget = $this->dockerRun($metadata, $dockerContainerName, $dockerRunDir);
        $this->mysql->initialize($dockerContainerName);
        // Not actually a retries amount, but that's fine here
        // @TODO: Implement waiting till MySQL completes deploying a DB dump!
        // $mysql->waitTillReady(Shell::EXECUTION_TIMEOUT_LONG);

        // Should we add both version and `:latest` tags?
        $output->writeln('Committing new image...');
        $imageName = $metadata[MysqlMetadataKeys::CONTAINER_REGISTRY] . ':latest';
        $this->shell->mustRun(sprintf('docker commit %s %s', $dockerContainerName, $imageName));

        $output->writeln('Restarting a container from a committed image...');
        $this->shell->mustRun(sprintf('docker rm -f %s', escapeshellarg($dockerContainerName)));
        $this->filesystem->remove(
            $dockerRunDir . DIRECTORY_SEPARATOR . 'mysql_initdb' . DIRECTORY_SEPARATOR . 'magento_db.sql.gz'
        );
        $metadataForImageTest = $metadata;
        $metadataForImageTest[MysqlMetadataKeys::DB_IMAGE] = $imageName;
        $this->dockerRun($metadataForImageTest, $dockerContainerName, $dockerRunDir, $myCnfMountTarget);

        $output->writeln('Check that tables are present in the database...');
        $mysql = $this->mysql->initialize($dockerContainerName);
        $statement = $mysql->prepareAndExecute('SHOW TABLES;');
        $result = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($result)) {
            throw new \InvalidArgumentException(
                'DB does not contain tables! Ensure that MySQL `datadir` is set in your `my.cnf` file1!'
            );
        }

        // @TODO: test with big DB

        // Push to the registry?
        // Remove everything


//        $output->setVerbosity($output::VERBOSITY_NORMAL);
//        $output->write($runContainerCommand);
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
     * @return array<string, string|string[]>
     * @throws \JsonException
     */
    private function downloadMetadata(InputInterface $input): array
    {
        $metadata = (string) $input->getOption('metadata');

        if (!$metadata) {
            $metadataFile = $this->env->getEnv(self::CI_CD_ENV_METADATA_FILE_NAME);
            throw new \RuntimeException('Implement downloading metadata ile');


            // @TODO: download data from AWS
            if (!is_file($metadataFile)) {
                throw new \RuntimeException("Metadata file $metadataFile does not exist!");
            }
        }

        $metadata = (array) json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->validateMetadata($metadata);

        return $metadata;
    }

    /**
     * @param array<string, string|string[]> $metadata
     * @param string $containerName
     * @return string
     */
    public function prepareForDockerRun(array $metadata, string $containerName): string
    {
        // Here we store
        $path = $this->filesystem->mkTmpDir($containerName);
        $this->filesystem->mkTmpDir($containerName . DIRECTORY_SEPARATOR . 'mysql_initdb');
        $myCnf = $path . DIRECTORY_SEPARATOR . 'my.cnf';
        $this->filesystem->filePutContents($myCnf, $metadata[MysqlMetadataKeys::MY_CNF]);

        return $path;
    }

    /**
     * @param array<string, string|string[]> $metadata
     * @param string $dockerContainerName
     * @param string $dockerRunDir
     * @param string $myCnfMountTarget
     * @return string - Mount target for `my.cnf` for testing the image after restart
     */
    private function dockerRun(
        array $metadata,
        string $dockerContainerName,
        string $dockerRunDir,
        string $myCnfMountTarget = '',
    ): string {
        // Select a proper docker compose configuration
        $dbImage = (string) $metadata[MysqlMetadataKeys::DB_IMAGE];

        if (!$myCnfMountTarget) {
            if (str_starts_with($dbImage, 'mysql:5')) {
                $myCnfMountTarget = '/etc/mysql/mysql.conf.d/zzz-my.cnf';
            } elseif (str_starts_with($dbImage, 'mysql:') || str_starts_with($dbImage, 'mariadb:')) {
                $myCnfMountTarget = '/etc/mysql/conf.d/zzz-my.cnf';
            } elseif (str_starts_with($dbImage, 'bitnami/mariadb:')) {
                $myCnfMountTarget = '/opt/bitnami/mariadb/conf/my_custom.cnf';
            } else {
                throw new \InvalidArgumentException("Unknown database image: $dbImage");
            }
        }

        $environment = '';

        if ($metadata[MysqlMetadataKeys::ENVIRONMENT]) {
            $environment .= '-e ' . implode(
                ' -e ',
                array_map('escapeshellarg', $metadata[MysqlMetadataKeys::ENVIRONMENT])
            );
        }

        $command = sprintf(
            self::DOCKER_RUN_MYSQL,
            $dockerContainerName,
            $dockerRunDir,
            $myCnfMountTarget,
            $dockerRunDir,
            $environment,
            $dbImage
        );

        $this->shell->mustRun($command);
        $this->registerImageForCleanup($dbImage);

        return $myCnfMountTarget;
    }

    /**
     * @param InputInterface $input
     * @param string $dockerRunDir
     * @return void
     */
    private function downloadDatabase(InputInterface $input, string $dockerRunDir): void
    {
        // We need this to get more information about the request from Amazon and its parameters
//        var_dump($_SERVER);
//        var_dump($_GET);
//        var_dump($_POST);

        // For testing we do not need to create a big DB. It's fine to take a Magento DB dump and test with it
        if ($input->getOption('metadata')) {
            $mysqlInitDbDir = $dockerRunDir . DIRECTORY_SEPARATOR . 'mysql_initdb';
            $this->filesystem->copy(
                dirname($dockerRunDir) . DIRECTORY_SEPARATOR . 'magento_db.sql.gz',
                $mysqlInitDbDir . DIRECTORY_SEPARATOR . 'magento_db.sql.gz'
            );

            return;
        }

        throw new \LogicException('Downloading Db from AWS is not implemented!');
    }

    /**
     * @param array<string, string|string[]> $metadata
     * @return void
     */
    private function validateMetadata(array $metadata): void
    {
        foreach (MysqlMetadataKeys::cases() as $case) {
            if (!isset($metadata[$case])) {
                throw new \RuntimeException(sprintf('Metadata key "%s" is missing', $case));
            }

            if (
                is_string($metadata[$case])
                && (!$metadata[$case] && $case !== MysqlMetadataKeys::MY_CNF)
            ) {
                throw new \RuntimeException(sprintf('Metadata key "%s" is empty', $case));
            }
        }
    }

    /**
     * @param string $imageName
     * @return void
     */
    private function registerImageForCleanup(string $imageName): void
    {
        $this->imagesToRemove[] = $imageName;
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
