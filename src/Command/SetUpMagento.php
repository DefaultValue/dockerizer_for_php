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
     * @var \App\Service\Database
     */
    private $database;
    /**
     * @var \App\Service\Filesystem
     */
    private $filesystem;

    /**
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Database $database
     * @param \App\Service\Filesystem $filesystem
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Database $database,
        \App\Service\Filesystem $filesystem,
        $name = null
    ) {
        parent::__construct($env, $shell, $questionPool, $name);

        $this->database = $database;
        $this->filesystem = $filesystem;
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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
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
            $mainDomainNameLength = strlen($mainDomain);

            $noInteraction = $input->getOption('no-interaction');
            $force = $input->getOption(self::OPTION_FORCE);

            $mysqlContainer = $this->ask(MysqlContainer::QUESTION, $input, $output);
            $databaseName = $this->database->getDatabaseName($mainDomain);
            $databaseUser = $this->database->getDatabaseUsername($mainDomain);

            if (
                !$noInteraction
                && (strlen($databaseName) < $mainDomainNameLength || strlen($databaseUser) < $mainDomainNameLength)
            ) {
                $question = new Question(<<<TEXT
                <info>Domain name is too long to use it for database username.
                Database and user will be: <fg=blue>$databaseName</fg=blue>
                Database user / password will be: <fg=blue>$databaseUser</fg=blue> / <fg=blue>$databaseName</fg=blue>
                Enter "Y" to continue: </info>
                TEXT);

                $proceedWithShortenedDbName = $this->getHelper('question')->ask($input, $output, $question);

                if (!$proceedWithShortenedDbName || strtolower($proceedWithShortenedDbName) !== 'y') {
                    throw new \LengthException(<<<'TEXT'
                    You decided not to continue with this domains and database name.
                    Use shorter domain name if possible.
                    TEXT);
                }
            }

            $projectRoot = $this->filesystem->getDir($mainDomain, true);
            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDir($mainDomain . DIRECTORY_SEPARATOR . 'pub', true);

            if ($force) {
                $this->cleanUp($mainDomain, $projectRoot);
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
            $this->passthru("cd $projectRoot && docker-compose down 2>/dev/null");
            sleep(1); // Fails to reinstall after cleanup on MacOS. Let's wait a little and test if this helps

            // 2. Run container so that now we can run commands inside it
            if (PHP_OS === 'Darwin') { // MacOS
                $this->passthru(<<<BASH
                    cd $projectRoot
                    docker-compose -f docker-compose.yml up -d --build --force-recreate
                BASH);
            } else {
                $this->passthru(<<<BASH
                    cd $projectRoot
                    docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --build --force-recreate
                BASH);
            }

            // 3. Remove all Docker files so that the folder is empty
            $this->dockerExec('sh -c "rm -rf *"');

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

            $this->dockerExec("composer $magentoCreateProject");

            $this->passthru(<<<BASH
                cd $projectRoot
                git init
                git config core.fileMode false
                git config user.name docker
                git config user.email docker@example.com
                git add -A
                git commit -m "Initial commit" -q
            BASH);

            // 5. Dockerize again so that we get all the same files and configs
            $this->dockerize($output, $phpVersion);
            $this->dockerExec('touch var/log/apache_error.log')
                ->dockerExec('chmod 777 -R generated/ pub/ var/ || :');

            $output->writeln('<info>Docker container should be ready. Trying to install Magento...</info>');

            $this->refreshDbAndInstall();

            $this->updateMagentoConfig();

            $this->dockerExec('php bin/magento cache:disable full_page block_html')
                ->dockerExec('php bin/magento deploy:mode:set developer')
                ->dockerExec('php bin/magento indexer:reindex');

            $this->copyAuthJson($projectRoot);

            $this->updateHosts();

            //@TODO: extend .gitignore and add .gitkeep to var/log/

            $output->writeln(<<<TEXT
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$domain</fg=blue>
            Admin Panel: <fg=blue>https://$domain/admin/</fg=blue>
            </info>
            TEXT);
        } catch (\Exception $e) {
            $this->cleanUp($mainDomain ?? '', $projectRoot ?? '');
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
    }

    /**
     * Clean up the installation folder in case of exception or process termination
     * @param string $mainDomain
     * @param string $projectRoot
     */
    private function cleanUp(string $mainDomain = '', string $projectRoot = ''): void
    {
        if (!$mainDomain || !$projectRoot) {
            return;
        }

        if (is_dir($projectRoot)) {
            // chown to be sure that the files are deletable
            $currentUser = get_current_user();

            $this->shell->passthru("cd $projectRoot && docker-compose down 2>/dev/null");
            $this->shell->sudoPassthru("chown -R $currentUser:$currentUser $projectRoot");
            $this->shell->passthru("rm -rf $projectRoot");
        }

        $this->database->dropDatabase($mainDomain);
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

    /**
     * Add domain to /etc/hosts if not there for 127.0.0.1
     * @param array $newDomains
     */
    private function updateHosts(array $newDomains): void
    {
        $hostsFileHandle = fopen('/etc/hosts', 'rb');
        $existingDomains = [];

        while ($line = fgets($hostsFileHandle)) {
            $isLocalhost = false;

            foreach ($lineParts = explode(' ', $line) as $string) {
                $string = trim($string); // remove line endings
                $string = trim($string, '#'); // remove comments

                if (!$isLocalhost && strpos($string, '127.0.0.1') !== false) {
                    $isLocalhost = true;
                }

                if ($isLocalhost && $this->domainValidator->isValid($string)) {
                    $existingDomains[] = $string;
                }
            }
        }

        fclose($hostsFileHandle);

        if ($domainsToAdd = array_diff($newDomains, $existingDomains)) {
            $hosts = '127.0.0.1 ' . implode(' ', $domainsToAdd);
            $this->shell->sudoPassthru("echo '$hosts' | sudo tee -a /etc/hosts");
        }
    }
}
