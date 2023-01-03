<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * @noinspection PhpUnused
 */
class GenerateMetadata extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:generate-metadata';

    // Used only to generate metadata by `docker:mysql:generate-metadata` for `docker:mysql:reconstruct-db`
    // You do not have to worry about this list if you do not plan to use the same functionality
    // Contact us otherwise. Contributions are welcome!
    private const SUPPORTED_DB_IMAGE_MYSQL = 'mysql:';
    private const SUPPORTED_DB_IMAGE_MARIADB = 'mariadb:';
    private const SUPPORTED_DB_IMAGE_BITNAMI_MARIADB = 'bitnami/mariadb:';

    public const COMMAND_ARGUMENT_CONTAINER = 'container';

    public const REGISTRY_DOMAIN = 'REGISTRY_DOMAIN';

    private const DEFAULT_MYSQL_DATADIR = 'datadir=/var/lib/mysql_datadir';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
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
            Generate DB metadata file for a given container. This metadata can be used to reconstruct the same container. For example, this can be useful to build DB images with CI/CD tools.

                <info>php %command.full_name% <container></info>
            EOF
        )
            ->addArgument(
                self::COMMAND_ARGUMENT_CONTAINER,
                InputArgument::REQUIRED,
                'Docker container name'
            )
            ->addOption(
                'target-image',
                't',
                InputOption::VALUE_OPTIONAL,
                'Docker image name including registry domain and excluding tags'
            )
            ->addOption(
                'aws-s3-bucket',
                '',
                InputOption::VALUE_OPTIONAL,
                'AWS S3 Bucket name to upload data. Pass it via options for non-interactive command execution'
            );
        // phpcs:enable
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dockerContainerName = $input->getArgument(self::COMMAND_ARGUMENT_CONTAINER);
        /** @var array<string, mixed> $containerMetadata */
        $containerMetadata = $this->docker->containerInspect($dockerContainerName);
        $mysql = $this->mysql->initialize($dockerContainerName);
        $vendorImage = $this->getVendorImageFromEnv($mysql, $containerMetadata);
        $output->writeln("Detected DB image: <info>$vendorImage</info>");

        $metadata = $this->mysqlMetadata->fromArray([
            MysqlMetadataKeys::VENDOR_IMAGE => $vendorImage,
            MysqlMetadataKeys::ENVIRONMENT => $this->getEnvironment($containerMetadata),
            MysqlMetadataKeys::MY_CNF_MOUNT_DESTINATION => $this->getMyCnfMountDestination($vendorImage),
            MysqlMetadataKeys::MY_CNF => $this->getMyCnf($output, $mysql, $containerMetadata),
            MysqlMetadataKeys::AWS_S3_BUCKET => $this->getAwsS3Bucket($input, $output, $mysql),
            MysqlMetadataKeys::TARGET_IMAGE => $this->getTargetImage($input, $output, $mysql)
        ]);

        $output->setVerbosity($output::VERBOSITY_NORMAL);
        $output->write($metadata->toJson());

        return self::SUCCESS;
    }

    /**
     * Get DB image from environment variables. We can't use image name for images from the registry,
     * but can still rely upon the environment variables from the container vendors.
     *
     * @param Mysql $mysql
     * @param array<string, mixed> $containerMetadata
     * @return string
     */
    public function getVendorImageFromEnv(Mysql $mysql, array $containerMetadata): string
    {
        if (
            $mysql->getEnvironmentVariable('MYSQL_MAJOR')
            && ($version = $mysql->getEnvironmentVariable('MYSQL_VERSION'))
        ) {
            return self::SUPPORTED_DB_IMAGE_MYSQL . substr($version, 0, (int) strpos($version, '-'));
        }

        if ($mysql->getEnvironmentVariable('MARIADB_VERSION')) {
            if (isset($containerMetadata['Config']['Labels']['org.opencontainers.image.version'])) {
                return self::SUPPORTED_DB_IMAGE_MARIADB
                    . $containerMetadata['Config']['Labels']['org.opencontainers.image.version'];
            }

            // MariaDB 10.1 and 10.2
            return self::SUPPORTED_DB_IMAGE_MARIADB . $mysql->getEnvironmentVariable('MARIADB_MAJOR');
        }

        if ($mysql->getEnvironmentVariable('BITNAMI_APP_NAME')) {
            if ($version = $mysql->getEnvironmentVariable('APP_VERSION')) {
                return self::SUPPORTED_DB_IMAGE_BITNAMI_MARIADB . $version;
            }

            return self::SUPPORTED_DB_IMAGE_BITNAMI_MARIADB . '10.1';
        }

        throw new \InvalidArgumentException('Unknown database type!');
    }

    /**
     * @param array<string, mixed> $containerMetadata
     * @return array<int, string>
     */
    private function getEnvironment(array $containerMetadata): array
    {
        return $containerMetadata['Config']['Env'];
    }

    /**
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @param array<string, mixed> $containerMetadata
     * @return string
     */
    private function getMyCnf(OutputInterface $output, Mysql $mysql, array $containerMetadata): string
    {
        foreach ($containerMetadata['Mounts'] as $mount) {
            if ($mount['Type'] !== 'bind') {
                continue;
            }

            if (str_ends_with($mount['Destination'], 'my.cnf')) {
                $process = $mysql->mustRun("cat {$mount['Destination']}", Shell::EXECUTION_TIMEOUT_SHORT, false);
                $myCnf = trim($process->getOutput());

                if (!str_contains($myCnf, "\ndatadir")) {
                    $output->writeln(
                        '\'datadir\' is not present in the \'my.cnf\' file. Setting it to \'/var/lib/mysql_datadir\''
                    );

                    if (str_contains($myCnf, '[mysqld]')) {
                        $myCnf = str_replace('[mysqld]', "[mysqld]\n" . self::DEFAULT_MYSQL_DATADIR, $myCnf);
                    } else {
                        $myCnf .= "[mysqld]\n" . self::DEFAULT_MYSQL_DATADIR . "\n";
                    }
                }

                return $myCnf;
            }
        }

        $output->writeln(
            'MySQL configuration file \'my.cnf\' not found. Using default configuration.'
        );

        return <<<'MYCNF'
            [mysqld]
            datadir=/var/lib/mysql_datadir
            wait_timeout=28800
            max_allowed_packet=128M
            innodb_log_file_size=128M
            innodb_buffer_pool_size=1G
            log_bin_trust_function_creators=1

            [mysql]
            auto-rehash
            MYCNF;
    }

    /**
     * @param string $dbImage
     * @return string
     */
    private function getMyCnfMountDestination(string $dbImage): string
    {
        if (str_starts_with($dbImage, 'mysql:5')) {
            return '/etc/mysql/mysql.conf.d/zzz-my.cnf';
        }

        if (str_starts_with($dbImage, 'mysql:') || str_starts_with($dbImage, 'mariadb:')) {
            return '/etc/mysql/conf.d/zzz-my.cnf';
        }

        if (str_starts_with($dbImage, 'bitnami/mariadb:')) {
            return '/opt/bitnami/mariadb/conf/my_custom.cnf';
        }

        throw new \InvalidArgumentException("Unknown database image: $dbImage");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @return string
     */
    public function getAwsS3Bucket(InputInterface $input, OutputInterface $output, Mysql $mysql): string
    {
        // Get from command parameters
        if ($bucket = (string) $input->getOption('aws-s3-bucket')) {
            return $bucket;
        }

        // Use env var, docker-compose.yaml data OR Git repository data to suggest bucket name
        return '';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @return string
     * @throws \JsonException
     */
    private function getTargetImage(InputInterface $input, OutputInterface $output, Mysql $mysql): string
    {
        // Get from command parameters
        if ($targetImage = (string) $input->getOption('target-image')) {
            return $targetImage;
        }

        $output->writeln('Trying to determine Docker registry domain for this DB image...');

        // Get from Docker image environment variables
        // @TODO: check docker-compose.yaml if available!
        if ($targetImage = $mysql->getEnvironmentVariable(MysqlMetadataKeys::TARGET_IMAGE)) {
            $output->writeln("Registry path defined in the Docker environment variables: $targetImage");

            if ($input->isInteractive()) {
                $question = new ConfirmationQuestion(
                    <<<'QUESTION'
                    Is this image name correct?
                    Anything starting with <info>y</info> or <info>Y</info> is accepted as yes.
                    >
                    QUESTION,
                    false,
                    '/^(y)/i'
                );
                $questionHelper = $this->getHelper('question');

                if (!$questionHelper->ask($input, $output, $question)) {
                    $targetImage = '';
                }
            }
        }

        return $targetImage ?: $this->askForTargetImage($input, $output, $mysql);
    }

    /**
     * Env variable REGISTRY_DOMAIN + git repository may be used as an image name.
     * Find them and ask user for confirmation!
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @return string
     * @throws \JsonException
     */
    private function askForTargetImage(InputInterface $input, OutputInterface $output, Mysql $mysql): string
    {
        return '';

        if (!$input->isInteractive()) {
            # === FOR TESTS ONLY ===
            return 'localhost:5000/' . uniqid('', false);

            throw new \InvalidArgumentException(
                'In the non-interactive mode you must pass Docker registry to push image to!'
            );
        }

        $registryDomain = '';
        $repositoryPath = '';

        try {
            $registryDomain = $this->env->getEnv(self::REGISTRY_DOMAIN);
        } catch (\Exception) {
            // phpcs:disable Generic.Files.LineLength.TooLong
            $output->writeln(
                '<error>Environment variable "REGISTRY_DOMAIN" is not set! Enter full image name including a domain if needed!</error>'
            );
            // phpcs:enable
        }

        $dockerComposeWorkdir = $this->docker->containerInspect(
            $mysql->getContainerName(),
            'index .Config.Labels "com.docker.compose.project.working_dir"'
        );

        // Check in the docker-compose.yaml. Just in case the user has not restarted composition
        // OR
        // Find repository, suggest pushing there
        if ($dockerComposeWorkdir) {
            try {
                $process = $this->shell->mustRun('git remote -v');

                $foo = false;

                if ($registryDomain) {

                }
            } catch (ProcessFailedException $e) {
            }
        }

        // user has provided data?
        if (false) {

        }


        // Just temporary test to see how it looks
        $output->writeln(
            '<error>Environment variable "REGISTRY_DOMAIN" is not set! Enter full image name including a domain if needed!</error>'
        );


        return '';


        // Suggest saving registry path to the image variables!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
    }
}
