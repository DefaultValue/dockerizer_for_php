<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\AWS\S3\Environment;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\DBType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class ReconstructDb extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:reconstruct-db';

    // Must be passed in the request that triggers a CI/CD job
    public const CI_CD_ENV_METADATA_FILE_NAME = 'METADATA_FILE_NAME';

    /**
     * A hardcoded list of supported and tested databases.
     * Use any other variation at your own responsibility.
     * Contributions in making this more flexible are welcome!
     *
     * @var array<string, string[]> $supportedDatabases
     */
    private array $supportedDatabases = [
        DBType::MYSQL => [
            '5.6' => 'dv_reconstruction_mysql_5_6_and_5_7',
            '5.7' => 'dv_reconstruction_mysql_5_6_and_5_7',
            '8.0' => 'dv_reconstruction_mysql_8_0'
        ],
        DBType::MARIADB => [
            '10.1' => 'dv_reconstruction_mariadb_10_1',
            '10.2' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.3' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.4' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.5' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.6' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.7' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.8' => 'dv_reconstruction_mariadb_10_2_and_above',
            '10.9' => 'dv_reconstruction_mariadb_10_2_and_above'
        ],
        DBType::BITNAMI_MARIADB => [
            '10.1' => 'dv_reconstruction_bitnami_mariadb',
            '10.2' => 'dv_reconstruction_bitnami_mariadb',
            '10.3' => 'dv_reconstruction_bitnami_mariadb',
            '10.4' => 'dv_reconstruction_bitnami_mariadb',
            '10.5' => 'dv_reconstruction_bitnami_mariadb',
            '10.6' => 'dv_reconstruction_bitnami_mariadb',
            '10.7' => 'dv_reconstruction_bitnami_mariadb',
            '10.8' => 'dv_reconstruction_bitnami_mariadb',
            '10.9' => 'dv_reconstruction_bitnami_mariadb',
        ]
    ];

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        parent::configure();

        // phpcs:disable Generic.Files.LineLength
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



    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Checking that AWS access parameters exist...');
        $this->validateAwsEnvParametersPresent();
        $output->writeln('Downloading database metadata file...');
        $metadata = $this->downloadMetadata($input);
        $output->writeln('Generating "docker run" command...');
        $runContainerCommand = $this->getDockerRunCommand($metadata);
        $output->writeln('Downloading database dump from AWS S3...');
        $this->downloadDatabase();

        $output->setVerbosity($output::VERBOSITY_NORMAL);
        $output->write($runContainerCommand);

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
            echo "Downloading DB metadata file...\n";
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
     * @return string
     */
    private function getDockerRunCommand(array $metadata): string
    {
        // Select a proper docker compose configuration
        $dbType = $metadata[MetadataKeys::DB_TYPE->value];
        $dbVersion = $metadata[MetadataKeys::DB_VERSION->value];
        $dbVersionShort = explode('.', $dbVersion)[0] . '.' . explode('.', $dbVersion)[1];
        $dbImage = DBType::from($dbType)->value;

        $dockerComposeYamlDist = self::$supportedDatabases[$dbType][$dbVersionShort]
            ?? throw new InvalidArgumentException("Unknown database configuration: $dbType:$dbVersionShort");
        $yaml = (string) file_get_contents("./databases/$dockerComposeYamlDist");

        // Guarantee that all DockerComposePlaceholders are covered
        foreach (DockerComposePlaceholders::cases() as $case) {
            $yaml = match ($case) {
                DockerComposePlaceholders::IMAGE => str_replace($case->value, "$dbImage:$dbVersion", $yaml),
                DockerComposePlaceholders::ENVIRONMENT =>
                str_replace($case->value, $this->getEnvironment($metadata[MetadataKeys::ENVIRONMENT->value]), $yaml)
            };
        }



        return $this;
    }

    private function downloadDatabase(): void
    {
        echo "Downloading database dump...\n";
        // We need this to get more information about the request from Amazon and its parameters
//        var_dump($_SERVER);
//        var_dump($_GET);
//        var_dump($_POST);

        // Download database from S3?
    }

    /**
     * @param array<string, string|string[]> $metadata
     * @return void
     */
    private function validateMetadata(array $metadata): void
    {
        foreach (MetadataKeys::cases() as $case) {
            if (!isset($metadata[$case->value])) {
                throw new RuntimeException(sprintf('Metadata key "%s" is missing', $case->value));
            }

            if (
                is_string($metadata[$case->value])
                && (!$metadata[$case->value] && $case !== MetadataKeys::MY_CNF)
            ) {
                throw new RuntimeException(sprintf('Metadata key "%s" is empty', $case->value));
            }
        }
    }

    /**
     * @param string[] $environmentVariables
     * @return string
     */
    private function getEnvironment(array $environmentVariables): string
    {
        if (empty($environmentVariables)) {
            return '';
        }

        $environment = '    environment:';
        $environment .= implode('      - ', $environmentVariables) . "/n";
        str_replace('$', '$$', $environment);

        return $environment;
    }

    private function save()
    {

    }
}
