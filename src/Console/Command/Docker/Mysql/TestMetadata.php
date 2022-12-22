<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Console\Command\Composition\BuildFromTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
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

        // phpcs:disable Generic.Files.LineLength
        $this->setHelp(<<<'EOF'
            Test the script that generates DB metadata files by running various containers, generating metadata and reconstructing them.
            The script to reconstruct DB is not public yet. Contact us if you're interested in building something like that.

                <info>php %command.full_name% <path-to-db-reconstructor></info>
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

        foreach (array_keys($template->getServices(Service::TYPE_OPTIONAL)['database']) as $database) {
            $callbacks[] = $this->getCallback($template->getCode(), $database);
        }

        $this->multithread->run($callbacks, $output, 0.5, 1, 1);

        return self::SUCCESS;
    }

    /**
     * 1. Generate a real composition and run it
     * 2. Generate metadata file for MySQL in that composition
     * 3. Shut down
     * 4. Give metadata to the `docker:mysql:reconstruct-db` command instead of downloading from AWS
     * Put some simple SQL file into the entrypoint directory
     * Run composition and ensure that custom DB table exists
     * Commit image
     * Shut down composition
     * Replace image with custom committed one
     * Remove the file from the entrypoint
     * Start composition again
     * Ensure the db table is still present
     * Shut down composition
     * Remove extra image - docker image rm database-test:1.0.0
     *
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
            // register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);

            try {
                // Steps 1-3: Get metadata
                $dockerCompose = $this->buildComposition($domain, $projectRoot, $templateCode, $database);
                $dockerCompose->up();
                $metadata = $this->getMetadata($dockerCompose->getServiceContainerName('mysql'));
                $dockerCompose->down();
                $this->logger->debug((string) json_encode(
                    json_decode($metadata, true, 512, JSON_THROW_ON_ERROR),
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                ));

                // Step 4: Generate DB composition from the metadata
                $this->reconstructDb($metadata);

                $foo = false;


                // What if we change `datadir` in `my.cnf`?
                // For example, original file does not have it and curent file has it.
                // Though, after starting a composition with this image we again get composition WITHOUT `datadir` in `my.cnf`
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
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     */
    private function buildComposition(
        string $domain,
        string $projectRoot,
        string $templateCode,
        string $database
    ): Compose {
        // build real composition to have where to get metadata from
        $command = $this->getApplication()->find('composition:build-from-template');
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
        $command->run($input, new NullOutput());
        $this->filesystem->mkdir($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');

        return array_values($this->compositionCollection->getList($projectRoot))[0];
    }

    /**
     * @param string $mysqlContainerName
     * @return string
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     */
    private function getMetadata(string $mysqlContainerName): string
    {
        $metadataCommand = $this->getApplication()->find('docker:mysql:generate-metadata');
        $input = new ArrayInput([
            'command' => 'docker:mysql:generate-metadata',
            'container-name' => $mysqlContainerName,
            '-n' => true,
            '-q' => true
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $metadataCommand->run($input, $output);

        return $output->fetch();
    }

    private function reconstructDb(string $metadata): void
    {
        $reconstructDbCommand = $this->getApplication()->find('docker:mysql:reconstruct-db');
        $input = new ArrayInput([
            'command' => 'docker:mysql:generate-metadata',
            '--metadata' => $metadata,
            '-n' => true,
            '-q' => true
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $reconstructDbCommand->run($input, $output);
        $reconstructDbShellCommand = $output->fetch();

        return;
    }
}
