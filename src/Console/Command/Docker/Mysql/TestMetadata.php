<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Console\Command\Composition\BuildFromTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata as MysqlMetadata;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Current implementation contains quite a lot of hardcode. Contributions are appreciated!
 *
 * @noinspection PhpUnused
 */
class TestMetadata extends \DefaultValue\Dockerizer\Console\Command\Composition\AbstractTestCommand
{
    protected static $defaultName = 'docker:mysql:test-metadata';

    public const TEMPLATE_WITH_DATABASES = 'generic_php_apache_app';

    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata $mysqlMetadata,
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        private string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $shell,
            $httpClient,
            $dockerizerRootDir,
            $name
        );
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        parent::configure();

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setHelp(<<<'EOF'
            Test the script that generates DB metadata files by running various containers, generating metadata and reconstructing them.
            This command will test everything locally without interacting with AWS S3 or pushing image to the Registry.

                <info>php %command.full_name% <path-to-db-reconstructor></info>

            Database dump path: <info>./var/tmp/database.sql.gz</info>
            EOF);
        // phpcs:enable
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Template $template */
        $template = $this->templateCollection->getByCode(self::TEMPLATE_WITH_DATABASES);
        $callbacks = [];

        /** @var string $database */
        foreach (array_keys($template->getServices(Service::TYPE_OPTIONAL)['database']) as $database) {
            $callbacks[] = $this->getCallback($template->getCode(), $database);
        }

        // 1 thread test with some delay between runs
        // $this->multithread->run($callbacks, $output, 0.5, 1, 3);
        $this->multithread->run($callbacks, $output, 0.5, 999, 1);

        return self::SUCCESS;
    }

    /**
     * @return \Closure
     */
    private function getCallback(string $templateCode, string $database): callable
    {
        return function () use (
            $templateCode,
            $database
        ) {
            // Re-init logger to have individual name for every callback that is run as a child process
            // This way we can identify logs for every callback
            $this->initLogger($this->dockerizerRootDir);
            $domain = sprintf('test-metadata-%s.local', str_replace('_', '-', $database));
            $projectRoot = $this->env->getProjectsRootDir() . $domain . DIRECTORY_SEPARATOR;
            register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);

            try {
                // Run real composition and collect metadata
                $this->logger->info('Build composition to get metadata for');
                $dockerCompose = $this->buildComposition($domain, $projectRoot, $templateCode, $database);
                $dockerCompose->up(false, true);

                $this->logger->info('Collect MySQL metadata');
                $metadataJson = $this->generateMetadata($dockerCompose->getServiceContainerName('mysql'));
                $this->logger->debug($metadataJson);
                $this->cleanUp($projectRoot);

                $this->logger->info('Reconstruct database');
                $metadata = $this->mysqlMetadata->fromJson($metadataJson);
                $this->reconstructDb($metadata);

                $this->logger->info('Completed all test for image: ' . $metadata->getVendorImage());
            } catch (\Throwable $e) {
                $this->logThrowable($e, "$templateCode > $database");

                throw $e;
            }
        };
    }

    /**
     * @param string $domain
     * @param string $projectRoot
     * @param string $templateCode
     * @param string $database
     * @return Compose
     * @throws ExceptionInterface
     */
    private function buildComposition(
        string $domain,
        string $projectRoot,
        string $templateCode,
        string $database
    ): Compose {
        // build real composition to have where to get metadata from
        $command = $this->getApplication()?->find('composition:build-from-template')
            ?? throw new \LogicException('Application is not initialized');
        $input = new ArrayInput([
            'command' => 'composition:build-from-template',
            '--' . CommandOptionForce::OPTION_NAME => true,
            '-n' => true,
            '-q' => true,
            '--' . BuildFromTemplate::OPTION_PATH => $projectRoot,
            '--' . CommandOptionDomains::OPTION_NAME => $domain,
            '--' . CommandOptionCompositionTemplate::OPTION_NAME => $templateCode,
            '--' . CommandOptionRequiredServices::OPTION_NAME => 'php_8_1_apache',
            '--' . CommandOptionOptionalServices::OPTION_NAME => $database,
            '--with-web_root' => ''
        ]);
        $input->setInteractive(false);
        $command->run($input, new NullOutput());
        $this->filesystem->mkdir($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');

        return array_values($this->compositionCollection->getList($projectRoot))[0];
    }

    /**
     * @param string $mysqlContainerName
     * @return string
     * @throws ExceptionInterface
     */
    private function generateMetadata(string $mysqlContainerName): string
    {
        $metadataCommand = $this->getApplication()?->find('docker:mysql:generate-metadata')
            ?? throw new \LogicException('Application is not initialized');
        $inputParameters = [
            'command' => 'docker:mysql:generate-metadata',
            GenerateMetadata::COMMAND_ARGUMENT_CONTAINER => $mysqlContainerName,
            '--target-image' => 'example.info:5000/owner/project/database' . uniqid('-', true),
            '--aws-s3-bucket' => 'example-bucket',
            '-n' => true,
            '-q' => true
        ];

        $input = new ArrayInput($inputParameters);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $metadataCommand->run($input, $output);

        return $output->fetch();
    }

    /**
     * @param MysqlMetadata $metadata
     * @return void
     * @throws \JsonException
     * @throws ExceptionInterface
     */
    private function reconstructDb(MysqlMetadata $metadata): void
    {
        // The following comes from AWS: AWS_S3_REGION, AWS_S3_BUCKET, AWS_S3_OBJECT_KEY
        // Setting them to test value so that they exist and validation passes
        putenv('AWS_S3_REGION=example-region');
        putenv('AWS_S3_BUCKET=example-bucket');
        putenv('AWS_S3_OBJECT_KEY=metadata.json');

        $command = $this->getApplication()?->find('docker:mysql:reconstruct-db')
            ?? throw new \LogicException('Application is not initialized');
        $input = new ArrayInput([
            'command' => 'docker:mysql:generate-metadata',
            '--metadata' => $metadata->toJson(),
            '--test-mode' => true,
            '-n' => true,
            '-q' => true
        ]);
        $input->setInteractive(false);
        $command->run($input, new NullOutput());
    }
}
