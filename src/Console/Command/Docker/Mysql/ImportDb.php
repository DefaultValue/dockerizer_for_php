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

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Container as CommandOptionContainer;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Exec as CommandOptionExec;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Container as CommandOptionDockerContainer;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\RuntimeException;

/**
 * @noinspection PhpUnused
 */
class ImportDb extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    use \DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait\TargetImage;

    protected static $defaultName = 'docker:mysql:import-db';

    public const MIME_TYPE_SQL = 'application/sql';
    public const MIME_TYPE_TEXT = 'text/plain';
    public const MIME_TYPE_GZIP = 'application/gzip';

    /**
     * @inheritdoc
     */
    protected array $commandSpecificOptions = [
        CommandOptionDockerContainer::OPTION_NAME,
        CommandOptionForce::OPTION_NAME,
        CommandOptionExec::OPTION_NAME
    ];

    /**
     * A list of known compressed files to avoid spending time and other resources on double compressing anything
     *
     * @var array<string, string> $compressedDumps
     */
    private array $compressedDumps = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setDescription('Update MySQL database in Docker container from  <info>.sql</info> or <info>.sql.gz</info> file')
            ->setHelp(<<<'EOF'
                Run <info>%command.name%</info> to update MySQL database.
                Supported dump file types are <info>.sql</info> and <info>.sql.gz</info>.
                File is copied inside the Docker container for import.
                Ensure there is enough free disk space to copy the dump file and import it.
                Note that MySQL container must have standard environment variables with DB name, user and password.
                See MySQL, MariDB, or Bitnami MariDB image documentation for more details.

                Example usage:

                    <info>php %command.full_name% <path_to_dump.sql.gz> -c <mysql_container_name></info>

                At the end you can choose to upload the dump to AWS S3 storage and thus trigger building a Docker container with the DB image.
                EOF)
            // phpcs:enable
            ->addArgument(
                'dump',
                \Symfony\Component\Console\Input\InputArgument::REQUIRED,
                'Path to the dump file. Can be either <info>.sql</info> or <info>.sql.gz</info> file'
            )
            // @TODO: fetch options from other commands instead of duplicating them.
            // We must do this dynamically, and ensure that 2-3 called commands do not have the same options
            // Take into account that required options from other commands may be passed from the "parent" one
            ->addOption(
                'target-image',
                't',
                InputOption::VALUE_OPTIONAL,
                'Docker image name including registry domain (if needed) and excluding tags'
            )
            // @TODO: add the ability to pass the region!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
            ->addOption(
                'bucket',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Pass bucket name to upload the dump to AWS S3 storage. See the \'docker:mysql:upload-to-aws\' command'
            )
            ->addOption(
                'aws',
                '',
                InputOption::VALUE_NONE,
                'Force upload to AWS on successful import'
            );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws \Exception
     * @throws ExceptionInterface
     */
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        $dump = (string) $input->getArgument('dump');

        if (!$this->filesystem->isFile($dump)) {
            throw new FileNotFoundException(null, 0, null, $dump);
        }

        $mimeType = mime_content_type($dump);

        if (!in_array($mimeType, [self::MIME_TYPE_SQL, self::MIME_TYPE_TEXT, self::MIME_TYPE_GZIP], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported file type: %s. Must be one of: %s, %s, %s',
                $mimeType,
                self::MIME_TYPE_SQL,
                self::MIME_TYPE_TEXT,
                self::MIME_TYPE_GZIP
            ));
        }

        // Try initializing MySQL service here to check that connection is possible
        // @TODO: Do we really want to deal with compositions and services instead of Docker containers only?
        $mysqlService = $this->initializeMysqlService($input, $output);
        $fileSize = 0;
        $freeDiskSpace = 0;

        if ($force = $this->getCommandSpecificOptionValue($input, $output, CommandOptionForce::OPTION_NAME)) {
            $output->writeln(
                'Force option is enabled. Ignoring filesize check and disk space requirements.'
            );
        } else {
            if ($mimeType === self::MIME_TYPE_SQL || $mimeType === self::MIME_TYPE_TEXT) {
                $fileSize = (new \SplFileInfo($dump))->getSize();
                $output->writeln('Dump file size: ' . $this->convertSize($fileSize));
            } else {
                $output->writeln('Calculating uncompressed file size. This may take some time for big files...');
                $process = $this->shell->mustRun(
                    "gzip -dc $dump | wc -c",
                    null,
                    [],
                    null,
                    Shell::EXECUTION_TIMEOUT_LONG
                );
                $fileSize = (int) $process->getOutput();
                $output->writeln('Uncompressed file size: ' . $this->convertSize($fileSize));
            }

            $freeDiskSpace = (int) disk_free_space('/');
            $output->writeln('Free disk space: ' . $this->convertSize($freeDiskSpace));
        }

        // If there is (theoretically) enough free space - copy dump and import it
        // 2.6 = 1 for dump file + 1 for db + 0.6 for indexes or other data structures
        if ($force) {
            $output->writeln('Importing dump from archive to save disk space in the force mode...');
            $importMethod = [$this, 'importFromArchive'];
        } elseif ($fileSize * 2.6 < $freeDiskSpace) {
            $output->writeln('Free space is enough to use MySQL SOURCE command. Importing...');
            $importMethod = [$this, 'importFromSqlFile'];
        // 1.7 = 0.1 for compressed dump file + 1 for db + 0.6 for indexes or other data structures
        } elseif ($fileSize * 1.7 < $freeDiskSpace) {
            $output->writeln('Not enough space to use MySQL SOURCE command! Importing dump from archive...');
            $importMethod = [$this, 'importFromArchive'];
        } else {
            throw new RuntimeException(sprintf(
                'Potentially not enough free disk space to import dump file. Expected free space is at least: %s',
                $this->convertSize($fileSize * 1.6)
            ));
        }

        try {
            $importMethod($input, $output, $dump, $mysqlService);
        } catch (\Exception $e) {
            $this->docker->run(
                'rm -rf /tmp/dump.sql /tmp/dump.sql.gz',
                // Bitnami MariaDB uses user 1000 for file and 1001 for docker exec
                "-u root {$mysqlService->getContainerName()}",
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );

            throw $e;
        }

        $this->uploadToAws($input, $output, $mysqlService, $dump);

        $output->writeln('Completed working with DB dump');

        return self::SUCCESS;
    }

    /**
     * @TODO: move all this logic to some service, trait, etc. This can be re-used for any command that requires
     * to choose a containerized service
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Mysql
     * @throws \Exception
     */
    private function initializeMysqlService(InputInterface $input, OutputInterface $output): Mysql
    {
        // Check `-c` option
        $mysqlContainerName = $this->getCommandSpecificOptionValue(
            $input,
            $output,
            CommandOptionDockerContainer::OPTION_NAME
        );
        // @TODO: get all running MySQL container with the respective environment variables and suggest to choose

//        // Check `-s` option
//        if (!$mysqlContainerName) {
//            $mysqlServiceName = $this->getCommandSpecificOptionValue(
//                $input,
//                $output,
//                CommandOptionDockerComposeService::OPTION_NAME
//            ) ?: self::DOCKER_COMPOSE_DEFAULT_MYSQL_SERVICE_NAME;
//
//            // Check we're in the directory with docker-compose.yaml file
//            try {
//                $composition = $this->dockerCompose->initialize((string) getcwd());
//            } catch (CompositionFilesNotFoundException) {
//                // Or ask to choose a composition from the list
//                $composition = $this->selectComposition($input, $output);
//            }
//
//            $mysqlContainerName = $composition->getServiceContainerName($mysqlServiceName);
//        }

        return $this->mysql->initialize($mysqlContainerName);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $dump
     * @param Mysql $mysqlService
     * @return void
     */
    private function importFromSqlFile(
        InputInterface $input,
        OutputInterface $output,
        string $dump,
        Mysql $mysqlService
    ): void {
        $mysqlContainerName = $mysqlService->getContainerName();
        $this->docker->copyFileToContainer($dump, $mysqlContainerName, '/tmp/dump.sql');
        $mimeType = mime_content_type($dump);

        if ($mimeType === self::MIME_TYPE_GZIP) {
            $output->writeln('Extracting dump file before import...');
            $mysqlService->mustRun('mv /tmp/dump.sql /tmp/dump.sql.gz');
            $mysqlService->mustRun('gunzip /tmp/dump.sql.gz', Shell::EXECUTION_TIMEOUT_LONG);
        }

        unset($mimeType);
        $mysqlDatabase = $mysqlService->getMysqlDatabase();
        $mysqlService->exec("DROP DATABASE IF EXISTS $mysqlDatabase");
        $mysqlService->exec("CREATE DATABASE $mysqlDatabase");
        // Importing a 40GB dump file may take a long time
        // Using the shell command to get all output and be able to see errors
        $mysqlUser = $mysqlService->getMysqlUser();
        $mysqlPassword = escapeshellarg($mysqlService->getMysqlPassword());

        $proceedToImport = $this->getCommandSpecificOptionValue($input, $output, CommandOptionExec::OPTION_NAME);

        if (!$proceedToImport) {
            // phpcs:disable Generic.Files.LineLength.TooLong
            $output->writeln(<<<TEXT
            Further commands to execute manually are:
            $ <info>docker exec -it $mysqlContainerName mysql --show-warnings -u$mysqlUser -p$mysqlPassword $mysqlDatabase</info>
            $ <info>SOURCE /tmp/dump.sql</info>
            $ <info>exit;</info>
            $ <info>docker exec -u root $mysqlContainerName rm /tmp/dump.sql</info>

            TEXT);
            // phpcs:enable

            if ($input->isInteractive()) {
                $proceedToImport = $this->confirm(
                    $input,
                    $output,
                    'Continue in the automatic mode? You will not be able to see the import progress and warnings!'
                );
            }
        }

        if ($proceedToImport) {
            $command = escapeshellarg(sprintf(
                // In this case MySQL will not stop import on error!
                // 'mysql --show-warnings -u%s -p%s %s -e "SOURCE /tmp/dump.sql"',
                'mysql -u%s -p%s %s < /tmp/dump.sql',
                $mysqlService->getMysqlUser(),
                escapeshellarg($mysqlService->getMysqlPassword()),
                $mysqlService->getMysqlDatabase()
            ));
            $output->writeln('Please wait. Import may take long time. This depends on the database size.');
            $this->docker->mustRun(
                "sh -c $command",
                $mysqlContainerName,
                Shell::EXECUTION_TIMEOUT_VERY_LONG
            );
            $output->writeln('Importing dump completed successfully!');

            // Bitnami MariaDB uses user 1000 for file and 1001 for docker exec
            $this->docker->mustRun('rm /tmp/dump.sql', "-u root $mysqlContainerName");
        } else {
            $output->writeln('You can continue import manually.');
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $dump
     * @param Mysql $mysqlService
     * @return void
     */
    private function importFromArchive(
        InputInterface $input,
        OutputInterface $output,
        string $dump,
        Mysql $mysqlService
    ): void {
        $mysqlContainerName = $mysqlService->getContainerName();
        $gzippedDump = $this->compressWithAutoremove($output, $dump);
        $this->docker->copyFileToContainer($gzippedDump, $mysqlContainerName, '/tmp/dump.sql.gz');
        $mysqlDatabase = $mysqlService->getMysqlDatabase();
        $mysqlService->exec("DROP DATABASE IF EXISTS $mysqlDatabase");
        $mysqlService->exec("CREATE DATABASE $mysqlDatabase");
        $importCommand = sprintf(
            'gzip -dc /tmp/dump.sql.gz | mysql -u%s -p%s %s',
            $mysqlService->getMysqlUser(),
            escapeshellarg($mysqlService->getMysqlPassword()),
            $mysqlDatabase
        );
        $output->writeln('Please wait. Import may take long time. This depends on the database size.');
        $mysqlService->mustRun('sh -c ' . escapeshellarg($importCommand), Shell::EXECUTION_TIMEOUT_VERY_LONG);
        $output->writeln('Importing dump completed successfully!');
        // Bitnami MariaDB uses user 1000 for file and 1001 for docker exec
        $this->docker->mustRun('rm /tmp/dump.sql.gz', "-u root $mysqlContainerName");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysqlService
     * @param string $dump
     * @return void
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     */
    private function uploadToAws(
        InputInterface $input,
        OutputInterface $output,
        Mysql $mysqlService,
        string $dump
    ): void {
        if (!$this->proceedUploadingToAws($input, $output)) {
            $output->writeln('Skipping upload to AWS S3');

            return;
        }

        $uploadToAwsCommand = $this->getApplication()?->find('docker:mysql:upload-to-aws')
            ?? throw new \LogicException('Application is not initialized');
        $targetImage = $this->getTargetImage(
            $input,
            $output,
            $this->getHelper('question'),
            $mysqlService->getLabel(GenerateMetadata::CONTAINER_LABEL_DOCKER_REGISTRY_TARGET_IMAGE)
        );

        $inputParameters = [
            'command' => 'docker:mysql:upload-to-aws',
            '--' . CommandOptionContainer::OPTION_NAME => $mysqlService->getContainerName(),
            '--dump' => $this->compressWithAutoremove($output, $dump),
            '--target-image' => $targetImage,
            '--bucket' => $input->getOption('bucket'),
        ];

        if (!$input->isInteractive()) {
            $inputParameters['-n'] = true;
        }

        $commandInput = new ArrayInput($inputParameters);
        $commandInput->setInteractive($input->isInteractive());
        $uploadToAwsCommand->run($commandInput, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return bool
     */
    private function proceedUploadingToAws(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->getOption('aws')) {
            return true;
        }

        if (
            $input->isInteractive()
            && $this->confirm($input, $output, 'Do you want to upload DB dump to the AWS S3 storage?')
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param float $bytes
     * @param int $decimals
     * @return string
     */
    private function convertSize(float $bytes, int $decimals = 2): string
    {
        $size = array('B','KB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . @$size[$factor];
    }

    /**
     * Create temporary file and compress original file. Remove temporary file on shutdown.
     * Returns original file name in case it is already a compressed file.
     *
     * @param OutputInterface $output
     * @param string $dump
     * @return string
     */
    private function compressWithAutoremove(OutputInterface $output, string $dump): string
    {
        if (mime_content_type($dump) === self::MIME_TYPE_GZIP) {
            return $dump;
        }

        if (isset($this->compressedDumps[$dump])) {
            return $this->compressedDumps[$dump];
        }

        $gzippedDump = $this->filesystem->tempnam(sys_get_temp_dir(), 'dockerizer_', '.sql.gz');
        $output->writeln('Compressing dump file before import...');

        // Register shutdown function beforehand to avoid keeping broken dump file
        register_shutdown_function(
            function (string $dbDumpHostPath) {
                if ($this->filesystem->isFile($dbDumpHostPath)) {
                    $this->filesystem->remove($dbDumpHostPath);
                }
            },
            $gzippedDump
        );

        $compressionCommand = sprintf('gzip --stdout %s > %s', escapeshellarg($dump), escapeshellarg($gzippedDump));
        $this->shell->mustRun($compressionCommand, null, [], null, Shell::EXECUTION_TIMEOUT_LONG);
        $this->compressedDumps[$dump] = $gzippedDump;

        return $gzippedDump;
    }

    /**
     * @TODO: check \Symfony\Component\Console\Style\SymfonyStyle and maybe use it to have a consistent style
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $question
     * @return bool
     */
    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        $confirmationQuestion = new ConfirmationQuestion(
            "\n$question\nAnything starting with <info>y</info> or <info>Y</info> is accepted as yes.\n> ",
            false,
            '/^(y)/i'
        );

        return $this->getHelper('question')->ask($input, $output, $confirmationQuestion);
    }
}
