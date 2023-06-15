<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class GenerateMetadata extends \Symfony\Component\Console\Command\Command
{
    use \DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait\TargetImage;

    protected static $defaultName = 'docker:mysql:generate-metadata';

    // Used only to generate metadata by `docker:mysql:generate-metadata` for `docker:mysql:reconstruct-db`
    // You do not have to worry about this list if you do not plan to use the same functionality
    // Contact us otherwise. Contributions are welcome!
    public const SUPPORTED_DB_IMAGE_MYSQL = 'mysql:';
    public const SUPPORTED_DB_IMAGE_MARIADB = 'mariadb:';
    public const SUPPORTED_DB_IMAGE_BITNAMI_MARIADB = 'bitnami/mariadb:';

    public const COMMAND_ARGUMENT_CONTAINER = 'container';

    public const CONTAINER_LABEL_DOCKER_REGISTRY_TARGET_IMAGE = 'com.default-value.docker.registry.target-image';

    private const DEFAULT_MYSQL_DATADIR = 'datadir=/var/lib/mysql_datadir';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
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
        $this->setDescription('Generate database metadata for the <info>docker:mysql:reconstruct-db</info> command')
            ->setHelp(sprintf(
                <<<'EOF'
                Generate DB metadata file for a given container. This metadata can be used to reconstruct the same container.

                    <info>php %%command.full_name%% <container></info>

                There are several ways to supply Docker image name:
                - Explicitly pass is via the '--target-image' option
                - Declare it as a Docker container label '%s'
                - Enter it manually when asked (interactive mode only)

                We recommend adding the environment name as a target image suffix, for example: <info>my-docker-registry.com:5000/namespace/repository/database-dev</info>
                E.g., add <info>-dev</info>, <info>-staging</info>, <info>-prod</info> to make distinguishing DBs easier.
                EOF,
                self::CONTAINER_LABEL_DOCKER_REGISTRY_TARGET_IMAGE
            ))
            ->addArgument(
                self::COMMAND_ARGUMENT_CONTAINER,
                InputArgument::REQUIRED,
                'MySQL Docker container name'
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
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $containerName = $input->getArgument(self::COMMAND_ARGUMENT_CONTAINER);
        $mysqlService = $this->mysql->initialize($containerName);
        $containerMetadata = $this->docker->containerInspect($containerName);
        $vendorImage = $this->getVendorImageFromEnv($mysqlService, $containerMetadata);
        $output->writeln("Detected DB image: <info>$vendorImage</info>");

        $metadata = $this->mysqlMetadata->fromArray([
            MysqlMetadataKeys::VENDOR_IMAGE => $vendorImage,
            MysqlMetadataKeys::ENVIRONMENT => $this->getEnvironment($containerMetadata),
            MysqlMetadataKeys::MY_CNF_MOUNT_DESTINATION => $this->getMyCnfMountDestination($vendorImage),
            MysqlMetadataKeys::MY_CNF => $this->getMyCnf($output, $mysqlService, $containerMetadata),
            MysqlMetadataKeys::TARGET_IMAGE => $this->getTargetImage(
                $input,
                $output,
                $this->getHelper('question'),
                $mysqlService->getLabel(self::CONTAINER_LABEL_DOCKER_REGISTRY_TARGET_IMAGE)
            )
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
     * @param Mysql $mysqlService
     * @param array<string, mixed> $containerMetadata
     * @return string
     */
    private function getMyCnf(OutputInterface $output, Mysql $mysqlService, array $containerMetadata): string
    {
        foreach ($containerMetadata['Mounts'] as $mount) {
            if ($mount['Type'] !== 'bind') {
                continue;
            }

            if (str_ends_with($mount['Destination'], 'my.cnf')) {
                $process = $mysqlService->mustRun("cat {$mount['Destination']}", Shell::EXECUTION_TIMEOUT_SHORT, false);
                $myCnf = trim($process->getOutput());

                if (!str_contains($myCnf, "\ndatadir")) {
                    $output->writeln(
                        '\'<info>datadir</info>\' is not present in the \'<info>my.cnf</info>\' file.' .
                        ' Setting it to \'<info>/var/lib/mysql_datadir</info>\''
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
        if (str_starts_with($dbImage, self::SUPPORTED_DB_IMAGE_MYSQL . '5')) {
            return '/etc/mysql/mysql.conf.d/zzz-my.cnf';
        }

        if (
            str_starts_with($dbImage, self::SUPPORTED_DB_IMAGE_MYSQL)
            || str_starts_with($dbImage, self::SUPPORTED_DB_IMAGE_MARIADB)
        ) {
            return '/etc/mysql/conf.d/zzz-my.cnf';
        }

        if (str_starts_with($dbImage, self::SUPPORTED_DB_IMAGE_BITNAMI_MARIADB)) {
            return '/opt/bitnami/mariadb/conf/my_custom.cnf';
        }

        throw new \InvalidArgumentException("Unknown database image: $dbImage");
    }
}
