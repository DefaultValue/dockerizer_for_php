<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\MysqlContainer;
use App\CommandQuestion\Question\PhpVersion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class SetUpMagento extends AbstractCommand
{
    public const OPTION_FORCE = 'force';

    /**
     * Magento version to PHP version mapping
     */
    private const MAGENTO_VERSION_TO_PHP_VERSION = [
        '2.0.0' => ['5.6', '7.0'],
        '2.1.0' => ['5.6', '7.0'],
        '2.2.0' => ['7.0', '7.1'],
        '2.3.0' => ['7.1', '7.2'],
        '2.3.3' => ['7.1', '7.2', '7.3'],
        '2.3.4' => ['7.2', '7.3']
    ];

    private const MAGENTO_REPOSITORY = 'https://%s:%s@repo.magento.com/';

    private const MAGENTO_PROJECT = 'magento/project-community-edition';

    /**
     * @var \App\Service\Database $database
     */
    private $database;

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * @var \App\Service\FileProcessor $fileProcessor
     */
    private $fileProcessor;

    /**
     * @var \App\Service\MagentoInstaller $magentoInstaller
     */
    private $magentoInstaller;

    /**
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Database $database
     * @param \App\Service\Filesystem $filesystem
     * @param \App\Service\FileProcessor $fileProcessor
     * @param \App\Service\MagentoInstaller $magentoInstaller
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Database $database,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        \App\Service\MagentoInstaller $magentoInstaller,
        $name = null
    ) {
        parent::__construct($env, $shell, $questionPool, $name);

        $this->database = $database;
        $this->filesystem = $filesystem;
        $this->fileProcessor = $fileProcessor;
        $this->magentoInstaller = $magentoInstaller;
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('setup:magento')
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Semantic Magento version like 2.2.10, 2.3.2 etc.'
            )->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Reinstall if the destination folder (domain name) is in use'
            )
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command deploys clean Magento instance of the selected version into the defined folder.
You will be asked to select PHP version if it has not been provided.

Simple usage:

    <info>php bin/console %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local"</info>

Install Magento with the pre-defined PHP version and MySQL container:

    <info>php bin/console %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local" --php=7.3 --mysql-container=mysql57</info>

Force install/reinstall Magento:
- with the latest supported PHP version;
- with MyQL 5.7;
- without questions;
- erase previous installation if the folder exists.

    <info>php bin/console %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local" -nf</info>

EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    public function getQuestions(): array
    {
        return [
            PhpVersion::QUESTION,
            MysqlContainer::QUESTION,
            Domains::QUESTION
        ];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $magentoVersion = $input->getArgument('version');

            if (((int) $magentoVersion[0]) !== 2 || substr_count($magentoVersion, '.') !== 2) {
                throw new \InvalidArgumentException(
                    'Magento version you\'ve entered doesn\'t follow semantic versioning and cannot be parsed'
                );
            }

            $domains = $this->ask(Domains::QUESTION, $input, $output);
            // Main domain will be used for database/user name, container name etc.
            $mainDomain = $domains[0];
            $noInteraction = $input->getOption('no-interaction');
            $force = $input->getOption(self::OPTION_FORCE);

            // Try creating project dir, check if it is empty and clean up if needed
            $projectRoot = $this->filesystem->getDirPath($mainDomain, true);

            if (!$this->filesystem->isEmptyDir($projectRoot)) {
                if ($force) {
                    $this->cleanUp($mainDomain);
                    $this->filesystem->getDirPath($mainDomain, true);
                } else {
                    // Unset variable so that project files are not removed in this particular case
                    unset($mainDomain);
                    throw new \InvalidArgumentException(<<<EOF
                    Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
                    Stop all containers (if any), remove the folder and re-run setup.
                    You can also use '-f' option to force install Magento with this domain.
                    EOF);
                }
            }

            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDirPath($mainDomain . DIRECTORY_SEPARATOR . 'pub', true);

            $mysqlContainer = $this->ask(MysqlContainer::QUESTION, $input, $output);
            $databaseName = $this->database->getDatabaseName($mainDomain);
            $databaseUser = $this->database->getDatabaseUsername($mainDomain);
            $mainDomainNameLength = strlen($mainDomain);

            if (
                !$noInteraction
                && (strlen($databaseName) < $mainDomainNameLength || strlen($databaseUser) < $mainDomainNameLength)
            ) {
                $question = new Question(<<<EOF
                <info>Domain name is too long to use it for database username.
                Database and user will be: <fg=blue>$databaseName</fg=blue>
                Database user / password will be: <fg=blue>$databaseUser</fg=blue> / <fg=blue>$databaseName</fg=blue>
                Enter <fg=blue>Y</fg=blue> to continue: </info>
                EOF);

                $proceedWithShortenedDbName = $this->getHelper('question')->ask($input, $output, $question);

                if (!$proceedWithShortenedDbName || strtolower($proceedWithShortenedDbName) !== 'y') {
                    throw new \LengthException(<<<'EOF'
                    You decided not to continue with this domains and database name.
                    Use shorter domain name if possible.
                    EOF);
                }
            }

            $compatiblePhpVersions = [];

            foreach (self::MAGENTO_VERSION_TO_PHP_VERSION as $m2platformVersion => $requiredPhpVersions) {
                if (version_compare($magentoVersion, $m2platformVersion, 'lt')) {
                    break;
                }

                $compatiblePhpVersions = $requiredPhpVersions;
            }

            $phpVersionQuestion = $this->ask(PhpVersion::QUESTION, $input, $output, $compatiblePhpVersions);

            // 1. Dockerize
            $this->dockerize($output, $projectRoot, $domains, $phpVersionQuestion, $mysqlContainer);

            // just in case previous setup was not successful
            $this->shell->passthru('docker-compose down 2>/dev/null', true, $projectRoot);
            sleep(1); // Fails to reinstall after cleanup on MacOS. Let's wait a little and test if this helps

            // 2. Run container so that now we can run commands inside it
            $command = (PHP_OS === 'Darwin')
                ? 'docker-compose -f docker-compose.yml up -d --build --force-recreate'
                : 'docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --build --force-recreate';
            $this->shell->passthru($command, false, $projectRoot);

            // 3. Remove all Docker files so that the folder is empty
            $this->shell->dockerExec('sh -c "rm -rf *"', $mainDomain);

            // 4. Create Magento project
            $authJson = $this->filesystem->getAuthJsonContent();
            $magentoRepositoryUrl = sprintf(
                self::MAGENTO_REPOSITORY,
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password']
            );
            $magentoCreateProject = sprintf(
                'create-project --repository=%s %s=%s /var/www/html',
                $magentoRepositoryUrl,
                self::MAGENTO_PROJECT,
                $input->getArgument('version')
            );

            $this->shell->dockerExec("composer $magentoCreateProject", $mainDomain);

            $this->shell->passthru(
                <<<BASH
                    git init
                    git config core.fileMode false
                    git config user.name "Dockerizer for PHP"
                    git config user.email user@example.com
                    git add -A
                    git commit -m "Initial commit" -q
                BASH,
                false,
                $projectRoot
            );

            // 5. Dockerize again so that we get all the same files and configs
            $this->dockerize($output, $projectRoot, $domains, $phpVersionQuestion, $mysqlContainer);

            $this->shell->dockerExec('chmod 777 -R generated/ pub/ var/ || :', $mainDomain);
            // Keep line indent - otherwise .gitignore will be formatted incorrectly
            $this->shell->passthru(
                <<<BASH
                    touch var/log/apache_error.log
                    touch var/log/.gitkeep
                    echo '!/var/log/' | tee -a .gitignore
                    echo '/var/log/*' | tee -a .gitignore
                    echo '!/var/log/.gitkeep' | tee -a .gitignore
                BASH,
                false,
                $projectRoot
            );

            $output->writeln('<info>Docker container should be ready. Trying to install Magento...</info>');

            $this->magentoInstaller->refreshDbAndInstall($mainDomain);
            $this->magentoInstaller->updateMagentoConfig($mainDomain);

            $this->shell->dockerExec('php bin/magento cache:disable full_page block_html', $mainDomain)
                ->dockerExec('php bin/magento deploy:mode:set developer', $mainDomain)
                ->dockerExec('php bin/magento indexer:reindex', $mainDomain);

            $this->filesystem->copyAuthJson($projectRoot);

            $this->fileProcessor->processHosts($domains);

            $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/admin/</fg=blue>
            </info>
            EOF);

            return 0;
        } catch (\Exception $e) {
            $this->cleanUp($mainDomain ?? '', $mysqlContainer ?? '');
            $output->writeln("<error>{$e->getMessage()}</error>");

            return 1;
        }
    }

    /**
     * Clean up the installation folder in case of exception or process termination
     * @param string $mainDomain
     * @param string $mysqlContainer
     */
    private function cleanUp(string $mainDomain = '', string $mysqlContainer = ''): void
    {
        if (!$mainDomain) {
            return;
        }

        try {
            $projectRoot = $this->filesystem->getDirPath($mainDomain);
            $this->shell->passthru('docker-compose down 2>/dev/null', true, $projectRoot);
            $this->shell->sudoPassthru("rm -rf $projectRoot");
        } catch (\Exception $e) {
        }

        if ($mysqlContainer) {
            $this->database->dropDatabase($mainDomain);
        }
    }

    /**
     * @param OutputInterface $output
     * @param string $projectRoot
     * @param array $domains
     * @param string $phpVersion
     * @param string $mysqlContainer
     * @throws \Exception
     */
    private function dockerize(
        OutputInterface $output,
        string $projectRoot,
        array $domains,
        string $phpVersion,
        string $mysqlContainer
    ): void {
        if (!$this->getApplication()) {
            // Just not to have a `Null pointer exception may occur here`
            throw new \RuntimeException('Application initialization failure');
        }

        $dockerize = $this->getApplication()->find('dockerize');

        $arguments = [
            'command' => 'dockerize',
            '--' . Dockerize::OPTION_PATH => $projectRoot,
            '--' . PhpVersion::OPTION_PHP_VERSION => $phpVersion,
            '--' . MysqlContainer::OPTION_MYSQL_CONTAINER => $mysqlContainer,
            '--' . Domains::OPTION_DOMAINS => $domains,
            '--' . Dockerize::OPTION_WEB_ROOT => 'pub/'
        ];

        $dockerizeInput = new ArrayInput($arguments);

        if ($dockerize->run($dockerizeInput, $output)) {
            throw new \RuntimeException('Can\'t dockerize the project');
        }
    }
}
