<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\MySQL;

use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\RuntimeException;

class UpdateDB extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:update-db';

    private const MIME_TYPE_SQL = 'application/sql';
    private const MIME_TYPE_TEXT = 'text/plain';
    private const MIME_TYPE_GZIP = 'application/gzip';


    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL $mysql
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL $mysql,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('Update MySQL database')
            ->setHelp(<<<'EOF'
                Run <info>%command.name%</info> to update MySQL database.
                Supported dump file types are <info>.sql</info> and <info>.sql.gz</info>.
                File is copied inside the Docker container for import.
                Ensure there is enough free disk space to copy the dump file and import it.
                Note that MySQL container myst have standard environment variables with DB name, user and password.
                See MySQL or Bitnami MariDB image documentation for more details. 

                Simple usage from the directory containing <info>docker-compose.yaml</info> file with <info>mysql</info> service:

                    <info>php %command.full_name%</info>
                EOF)
            ->addArgument(
                'file',
                \Symfony\Component\Console\Input\InputArgument::REQUIRED,
                'Path to a MySQL dump file: <info>.sql</info> or <info>sql.gz</info>'
            );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        $file = $input->getArgument('file');

        if (!$this->filesystem->isFile($file)) {
            throw new FileNotFoundException(null, 0, null, $file);
        }

        $mimeType = mime_content_type($file);

        if (!in_array($mimeType, [self::MIME_TYPE_SQL, self::MIME_TYPE_TEXT, self::MIME_TYPE_GZIP], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported file type: %s. Must be one of: %s, %s, %s',
                $mimeType,
                self::MIME_TYPE_SQL,
                self::MIME_TYPE_TEXT,
                self::MIME_TYPE_GZIP
            ));
        }

        // @TODO: pass container name as an argument. Otherwise take it from the docker-compose.yml file
        // @TODO: make service name configurable
        // if (!$input->getArgument('container')) {...}
        $dockerCompose = $this->dockerCompose->initialize(getcwd());
        $mysqlContainerName = $dockerCompose->getServiceContainerName('mysql');
        // Try initializing MySQL service here to check that connection is possible
        $mysqlService = $this->mysql->initialize($mysqlContainerName);

        if ($mimeType === self::MIME_TYPE_SQL || $mimeType === self::MIME_TYPE_TEXT) {
            $fileSize = (new \SplFileInfo($file))->getSize();
            $output->writeln('Dump file size: ' . $this->convertSize($fileSize));
        } else {
            $output->writeln('Calculating uncompressed file size. This may take some time for big files...');
            $process = $this->shell->mustRun("gzip -dc $file | wc -c", null, [], null, Shell::EXECUTION_TIMEOUT_LONG);
            $fileSize = (int) $process->getOutput();
            $output->writeln('Uncompressed file size: ' . $this->convertSize($fileSize));
        }

        $freeDiskSpace = (int) disk_free_space('/');
        $output->writeln('Free disk space: ' . $this->convertSize($freeDiskSpace));

        // If there is (theoretically) enough free space - copy dump and import it
        // 2.6 = 1 for dump file + 1 for db + 0.6 for indexes or other data structures
        if ($fileSize * 2.6 < $freeDiskSpace) {
            $output->writeln('Free space is enough to use MySQL SOURCE command');
            $importMethod = [$this, 'importFromSqlFile'];
        // 1.7 = 0.1 for compressed dump file + 1 for db + 0.6 for indexes or other data structures
        } elseif ($fileSize * 1.7 < $freeDiskSpace) {
            $output->writeln('Not enough space to use MySQL SOURCE command! Importing dump from archive');
            $importMethod = [$this, 'importFromArchive'];
        } else {
            throw new RuntimeException(sprintf(
                'Potentially not enough free disk space to import dump file. Expected free space is at least: %s',
                $this->convertSize($fileSize * 1.6)
            ));
        }

        try {
            $importMethod($input, $output, $file, $mysqlService);
        } catch (\Exception $e) {
            $this->docker->run(
                'rm -rf /tmp/dump.sql /tmp/dump.sql.gz',
                // Bitnami MariaDB uses user 1000 for file and 1001 for docker exec
                "-u root $mysqlContainerName",
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );

            throw $e;
        }

        return 0;
    }

    /**
     * @param Input $input
     * @param Output $output
     * @param string $file
     * @param MySQL $mysqlService
     * @return void
     */
    private function importFromSqlFile(Input $input, Output $output, string $file, MySQL $mysqlService): void
    {
        $mysqlContainerName = $mysqlService->getContainerName();
        $this->docker->copyFileToContainer($file, $mysqlContainerName, '/tmp/dump.sql');
        $mimeType = mime_content_type($file);

        if ($mimeType === self::MIME_TYPE_GZIP) {
            $output->writeln('Extracting dump file before import...');
            $mysqlService->mustRun('mv /tmp/dump.sql /tmp/dump.sql.gz');
            $mysqlService->mustRun('gunzip /tmp/dump.sql.gz', Shell::EXECUTION_TIMEOUT_LONG);
        }

        unset($mimeType);
        $mysqlDatabase = $mysqlService->getMySQLDatabase();
        $mysqlService->exec("DROP DATABASE IF EXISTS $mysqlDatabase");
        $mysqlService->exec("CREATE DATABASE $mysqlDatabase");
        // Importing a 40GB dump file may take a long time
        // Using the shell command to get all output and be able to see errors
        $mysqlUser = $mysqlService->getMySQLUser();
        $mysqlPassword = escapeshellarg($mysqlService->getMySQLPassword());

        $output->writeln(<<<TEXT
            Further commands to execute manually are:
            $ <info>docker exec -it $mysqlContainerName mysql -u$mysqlUser -p$mysqlPassword $mysqlDatabase</info>
            $ <info>SOURCE /tmp/dump.sql</info>
            $ <info>exit;</info>
            $ <info>docker exec -u root -it $mysqlContainerName rm /tmp/dump.sql</info>

            TEXT);
        $proceedToImport = true;

        if ($input->isInteractive()) {
            $question = new ConfirmationQuestion(
                <<<'QUESTION'
                Continue in the automatic mode? You will not be able to see the import progress and warnings!
                Anything starting with <info>y</info> or <info>Y</info> is accepted as yes.
                > 
                QUESTION,
                false,
                '/^(y|j)/i'
            );
            $questionHelper = $this->getHelper('question');
            $proceedToImport = $questionHelper->ask($input, $output, $question);
        }

        if ($proceedToImport) {
            $command = escapeshellarg(sprintf(
                // In this case MySQL will not stop import on error!
                // 'mysql --show-warnings -u%s -p%s %s -e "SOURCE /tmp/dump.sql"',
                'mysql --show-warnings -u%s -p%s %s < /tmp/dump.sql',
                $mysqlService->getMySQLUser(),
                escapeshellarg($mysqlService->getMySQLPassword()),
                $mysqlService->getMySQLDatabase()
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
     * @param Input $input
     * @param Output $output
     * @param string $file
     * @param MySQL $mysqlService
     * @return void
     */
    private function importFromArchive(Input $input, Output $output, string $file, MySQL $mysqlService): void
    {
        $mysqlContainerName = $mysqlService->getContainerName();
        $mimeType = mime_content_type($file);
        $gzippedFile = $file;

        if ($mimeType !== self::MIME_TYPE_GZIP) {
            $output->writeln('Compressing dump file before import...');
            $this->shell->mustRun("gzip -k $file", null, [], null, Shell::EXECUTION_TIMEOUT_LONG);
            $gzippedFile .= '.gz';
        }

        unset($mimeType);
        $this->docker->copyFileToContainer($gzippedFile, $mysqlContainerName, '/tmp/dump.sql.gz');
        $mysqlDatabase = $mysqlService->getMySQLDatabase();
        $mysqlService->exec("DROP DATABASE IF EXISTS $mysqlDatabase");
        $mysqlService->exec("CREATE DATABASE $mysqlDatabase");
        $importCommand = sprintf(
            'gzip -dc /tmp/dump.sql.gz | mysql -u%s -p%s %s',
            $mysqlService->getMySQLUser(),
            escapeshellarg($mysqlService->getMySQLPassword()),
            $mysqlDatabase
        );
        $output->writeln('Please wait. Import may take long time. This depends on the database size.');
        $mysqlService->mustRun('sh -c ' . escapeshellarg($importCommand), Shell::EXECUTION_TIMEOUT_VERY_LONG);
        $output->writeln('Importing dump completed successfully!');
        // Bitnami MariaDB uses user 1000 for file and 1001 for docker exec
        $this->docker->mustRun('rm /tmp/dump.sql.gz', "-u root $mysqlContainerName");
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
}
