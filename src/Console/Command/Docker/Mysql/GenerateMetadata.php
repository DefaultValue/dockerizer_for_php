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

    private const COMMAND_ARGUMENT_CONTAINER_NAME = 'container-name';

    public const REGISTRY_DOMAIN = 'REGISTRY_DOMAIN';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
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

                <info>php %command.full_name% <container-name></info>
            EOF)
            ->addArgument(
                self::COMMAND_ARGUMENT_CONTAINER_NAME,
                InputArgument::REQUIRED,
                'Docker container name'
            )
            ->addOption(
                'registry',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Target Docker registry'
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
        $dockerContainerName = $input->getArgument(self::COMMAND_ARGUMENT_CONTAINER_NAME);
        /** @var array<string, mixed> $containerMetadata */
        $containerMetadata = $this->docker->containerInspect($dockerContainerName);
        $mysql = $this->mysql->initialize($dockerContainerName);
        $dbImage = $this->getDbImageFromEnv($mysql, $containerMetadata);
        $output->writeln("Detected DB image: <info>$dbImage</info>");

        $databaseMetadata = [
            MysqlMetadataKeys::DB_IMAGE => $dbImage,
            MysqlMetadataKeys::ENVIRONMENT => $this->getEnvironment($containerMetadata),
            MysqlMetadataKeys::MY_CNF => $this->getMyCnf($mysql, $containerMetadata),
            MysqlMetadataKeys::CONTAINER_REGISTRY => $this->getTargetRegistry($input, $output, $mysql)
        ];

        $output->setVerbosity($output::VERBOSITY_NORMAL);
        $output->write(json_encode($databaseMetadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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
    public function getDbImageFromEnv(Mysql $mysql, array $containerMetadata): string
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
     * @param Mysql $mysql
     * @param array<string, mixed> $containerMetadata
     * @return string
     */
    private function getMyCnf(Mysql $mysql, array $containerMetadata): string
    {
        foreach ($containerMetadata['Mounts'] as $mount) {
            if ($mount['Type'] !== 'bind') {
                continue;
            }

            if (str_ends_with($mount['Destination'], 'my.cnf')) {
                $process = $mysql->mustRun("cat {$mount['Destination']}", Shell::EXECUTION_TIMEOUT_SHORT, false);

                return trim($process->getOutput());
            }
        }

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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @return string
     */
    private function getTargetRegistry(InputInterface $input, OutputInterface $output, Mysql $mysql): string
    {
        // Get from command parameters
        if ($registry = (string) $input->getOption('registry')) {
            return $registry;
        }

        $output->writeln('Trying to determine Docker registry domain for this DB image...');

        // Get from Docker image environment variables
        if ($registry = $mysql->getEnvironmentVariable(MysqlMetadataKeys::CONTAINER_REGISTRY)) {
            $output->writeln("Registry path defined in the Docker environment variables: $registry");

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
                    $registry = '';
                }
            }
        }

        if ($registry) {
            return $registry;
        }

        return $this->askForImageRegistry($input, $output, $mysql);
    }

    /**
     * Env variable REGISTRY_DOMAIN + git repository may be used as an image name.
     * Find them and ask user for confirmation!
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @return string
     */
    private function askForImageRegistry(InputInterface $input, OutputInterface $output, Mysql $mysql): string
    {
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
        $foo = false;


        // Suggest saving registry path to the image variables!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
    }
}
