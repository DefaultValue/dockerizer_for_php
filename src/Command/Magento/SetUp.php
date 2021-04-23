<?php

declare(strict_types=1);

namespace App\Command\Magento;

use App\Command\Dockerize;
use App\CommandQuestion\Question\ComposerVersion;
use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\MysqlContainer;
use App\CommandQuestion\Question\PhpVersion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class SetUp extends \App\Command\AbstractCommand
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
        '2.3.4' => ['7.2', '7.3'],
        '2.4.0' => ['7.3', '7.4']
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
     * @param ?string $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Database $database,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        \App\Service\MagentoInstaller $magentoInstaller,
        ?string $name = null
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
        $this->setName('magento:setup')
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Semantic Magento version like 2.2.10, 2.3.2 etc.'
            )->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Reinstall if the destination folder (domain name) is in use'
            )->addOption(
                Dockerize::OPTION_EXECUTION_ENVIRONMENT,
                'e',
                InputOption::VALUE_OPTIONAL,
                'Use local Dockerfile from the Docker Infrastructure repository instead of the prebuild DockerHub image'
            )
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command deploys clean Magento instance of the selected version into the defined folder.
You will be asked to select PHP version if it has not been provided.

Simple usage:

    <info>php %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local"</info>

Install Magento with the pre-defined PHP version and MySQL container:

    <info>php %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local" --php=7.3 --mysql-container=mysql57</info>

Force install/reinstall Magento:
- with the latest supported PHP version;
- with MyQL 5.7;
- without questions;
- erase previous installation if the folder exists.

    <info>php %command.full_name% 2.3.4 --domains="magento-234.local www.magento-234.local" -nf</info>

EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function getQuestions(): array
    {
        return [
            PhpVersion::OPTION_NAME,
            MysqlContainer::OPTION_NAME,
            Domains::OPTION_NAME
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

            $domains = $this->ask(Domains::OPTION_NAME, $input, $output);
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

            $mysqlContainer = $this->ask(MysqlContainer::OPTION_NAME, $input, $output);
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

            // PHP versions
            $compatiblePhpVersions = [];

            foreach (self::MAGENTO_VERSION_TO_PHP_VERSION as $m2platformVersion => $requiredPhpVersions) {
                if (version_compare($magentoVersion, $m2platformVersion, 'lt')) {
                    break;
                }

                $compatiblePhpVersions = $requiredPhpVersions;
            }

            $phpVersion = $this->ask(PhpVersion::OPTION_NAME, $input, $output, $compatiblePhpVersions);

            $composerVersion = 2;

            if (
                $magentoVersion === '2.4.0'
                || $magentoVersion === '2.4.1'
                || version_compare($magentoVersion, '2.3.7', 'lt')
            ) {
                $composerVersion = 1;
            }

            // Elasticsearch - quick implementation before adding the ability to populate docker-compose.yml files
            // with any available services
            $elasticsearchVersion = version_compare($magentoVersion, '2.4.0', 'lt') ? '' : '7.6.2';
            $elasticsearchHost = version_compare($magentoVersion, '2.4.0', 'lt') ? '' : 'elasticsearch';

            // Execution environment to use full local Dockerfile if needed
            $executionEnvironment = $input->getOption(Dockerize::OPTION_EXECUTION_ENVIRONMENT);

            // 1. Dockerize
            $this->dockerize(
                $output,
                $projectRoot,
                $domains,
                $phpVersion,
                $composerVersion,
                $mysqlContainer,
                $elasticsearchVersion,
                $executionEnvironment
            );

            // just in case previous setup was not successful
            $this->shell->passthru('docker-compose down 2>/dev/null', true, $projectRoot);
            sleep(1); // Fails to reinstall after cleanup on MacOS. Let's wait a little and test if this helps

            // 2. Run container so that now we can run commands inside it
            $this->shell->passthru(
                'docker-compose up -d --build --force-recreate',
                false,
                $projectRoot
            );

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

            // Hotfix for Magento 2.4.1
            if (!file_exists("{$projectRoot}.gitignore")) {
                $this->addGitignoreFrom240($projectRoot);
            }

            $this->shell->exec('git init', $projectRoot);
            $this->shell->exec('git config core.fileMode false', $projectRoot, true);

            // Set user name if not is set globally
            try {
                $this->shell->exec('git config user.name', $projectRoot)[0];
            } catch (\Exception $e) {
                $this->shell->exec('git config user.name "Dockerizer for PHP"', $projectRoot, true);
            }

            // Set user email if not is set globally
            try {
                $this->shell->exec('git config user.email', $projectRoot)[0];
            } catch (\Exception $e) {
                $this->shell->exec('git config user.email email@example.com', $projectRoot, true);
            }

            $this->shell->exec('git add -A', $projectRoot, true);
            $this->shell->exec('git commit -m "Initial commit" -q', $projectRoot, true);

            // 5. Dockerize again so that we get all the same files and configs
            $this->dockerize(
                $output,
                $projectRoot,
                $domains,
                $phpVersion,
                $composerVersion,
                $mysqlContainer,
                $elasticsearchVersion,
                $executionEnvironment
            );

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

            $this->magentoInstaller->refreshDbAndInstall(
                $mainDomain,
                $magentoVersion === '2.4.0' && $phpVersion === '7.3',
                $elasticsearchHost
            );
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

            if ($this->filesystem->isWritableFile($projectRoot . 'docker-compose.yml')) {
                $this->shell->passthru('docker-compose down 2>/dev/null', true, $projectRoot);
            } else {
                // Handle the case when we fail while installing Magento and do not have the docker-compose.yml
                $mainDockerContainer = str_replace('.', '', $mainDomain);

                try {
                    // For some reasons this command may return error if no containers were found
                    $dockerContainers = $this->shell->exec("docker ps | grep $mainDockerContainer", '', true);
                    $dockerContainers = array_map(static function ($value) {
                        return array_values(array_filter(explode(' ', $value)))[1];
                    }, $dockerContainers);
                } catch (\Exception $e) {
                    $dockerContainers = [];
                }

                foreach ($dockerContainers as $dockerContainer) {
                    $this->shell->passthru(
                        "docker stop $dockerContainer && docker rm $dockerContainer",
                        true,
                        $projectRoot
                    );
                }
            }

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
     * @param int $composerVersion
     * @param string $mysqlContainer
     * @param string $elasticsearchVersion
     * @param string|null $executionEnvironment
     * @throws \Exception
     */
    private function dockerize(
        OutputInterface $output,
        string $projectRoot,
        array $domains,
        string $phpVersion,
        int $composerVersion,
        string $mysqlContainer,
        string $elasticsearchVersion = '',
        ?string $executionEnvironment = null
    ): void {
        if (!$this->getApplication()) {
            // Just not to have a `Null pointer exception may occur here`
            throw new \RuntimeException('Application initialization failure');
        }

        $dockerize = $this->getApplication()->find('dockerize');

        $arguments = [
            'command' => 'dockerize',
            '--' . Dockerize::OPTION_PATH => $projectRoot,
            '--' . PhpVersion::OPTION_NAME => $phpVersion,
            '--' . ComposerVersion::OPTION_NAME => $composerVersion,
            '--' . MysqlContainer::OPTION_NAME => $mysqlContainer,
            '--' . Domains::OPTION_NAME => $domains,
            '--' . Dockerize::OPTION_WEB_ROOT => 'pub/'
        ];

        if ($elasticsearchVersion) {
            $arguments['--' . Dockerize::OPTION_ELASTICSEARCH] = $elasticsearchVersion;
        }

        if ($executionEnvironment) {
            $arguments['--' . Dockerize::OPTION_EXECUTION_ENVIRONMENT] = $executionEnvironment;
        }

        $dockerizeInput = new ArrayInput($arguments);

        if ($dockerize->run($dockerizeInput, $output)) {
            throw new \RuntimeException('Can\'t dockerize the project');
        }
    }

    /**
     * @param string $projectRoot
     */
    private function addGitignoreFrom240(string $projectRoot): void
    {
        file_put_contents(
            "{$projectRoot}.gitignore",
            <<<GITIGNORE
            /.buildpath
            /.cache
            /.metadata
            /.project
            /.settings
            /.vscode
            atlassian*
            /nbproject
            /robots.txt
            /pub/robots.txt
            /sitemap
            /sitemap.xml
            /pub/sitemap
            /pub/sitemap.xml
            /.idea
            /.gitattributes
            /app/config_sandbox
            /app/etc/config.php
            /app/etc/env.php
            /app/code/Magento/TestModule*
            /lib/internal/flex/uploader/.actionScriptProperties
            /lib/internal/flex/uploader/.flexProperties
            /lib/internal/flex/uploader/.project
            /lib/internal/flex/uploader/.settings
            /lib/internal/flex/varien/.actionScriptProperties
            /lib/internal/flex/varien/.flexLibProperties
            /lib/internal/flex/varien/.project
            /lib/internal/flex/varien/.settings
            /node_modules
            /.grunt
            /Gruntfile.js
            /package.json
            /.php_cs
            /.php_cs.cache
            /grunt-config.json
            /pub/media/*.*
            !/pub/media/.htaccess
            /pub/media/attribute/*
            !/pub/media/attribute/.htaccess
            /pub/media/analytics/*
            /pub/media/catalog/*
            !/pub/media/catalog/.htaccess
            /pub/media/customer/*
            !/pub/media/customer/.htaccess
            /pub/media/downloadable/*
            !/pub/media/downloadable/.htaccess
            /pub/media/favicon/*
            /pub/media/import/*
            !/pub/media/import/.htaccess
            /pub/media/logo/*
            /pub/media/custom_options/*
            !/pub/media/custom_options/.htaccess
            /pub/media/theme/*
            /pub/media/theme_customization/*
            !/pub/media/theme_customization/.htaccess
            /pub/media/wysiwyg/*
            !/pub/media/wysiwyg/.htaccess
            /pub/media/tmp/*
            !/pub/media/tmp/.htaccess
            /pub/media/captcha/*
            /pub/static/*
            !/pub/static/.htaccess

            /var/*
            !/var/.htaccess
            /vendor/*
            !/vendor/.htaccess
            /generated/*
            !/generated/.htaccess
            .DS_Store

            GITIGNORE
        );
    }
}
