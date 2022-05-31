<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use DefaultValue\Dockerizer\Console\Shell\Shell;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\Compose\CompositionFilesNotFoundException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Install Magento
 *
 * Composition template and services myst be configured in the respective command (or web app if we ever get it)
 */
class Installer
{
    private const PHP_SERVICE = 'php';
    private const MYSQL_SERVICE = 'mysql';
    private const ELASTICSEARCH_SERVICE = 'elasticsearch';
    private const VARNISH_SERVICE = 'varnish-cache';

    private const MAGENTO_REPOSITORY = 'https://%s:%s@repo.magento.com/';

    private const MAGENTO_PROJECT = 'magento/project-community-edition';

    // @TODO: add file hash validation
    private const COMPOSER_1_DOWNLOAD_URL = 'https://getcomposer.org/download/1.10.26/composer.phar';

    /**
     * Magento composer plugins that must be allowed if we do not want to answer Composer questions
     */
    private const ALLOWED_PLUGINS = [
        'hirak/prestissimo',
        'laminas/laminas-dependency-plugin',
        'dealerdirect/phpcodesniffer-composer-installer',
        'magento/composer-dependency-version-audit-plugin',
        'magento/composer-root-update-plugin',
        'magento/inventory-composer-installer',
        'magento/magento-composer-installer'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem ,
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition ,
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose ,
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker ,
     * @param \DefaultValue\Dockerizer\Docker\Container\Php $phpContainer ,
     * @param \DefaultValue\Dockerizer\Docker\Container\MySQL $mysqlContainer
     * @param Shell $shell
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Docker\Container\Php $phpContainer,
        private \DefaultValue\Dockerizer\Docker\Container\MySQL $mysqlContainer,
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell
    ) {
    }

    /**
     * Install Magento from creating a directory to running application
     *
     * @param OutputInterface $output
     * @param string $magentoVersion
     * @param array $domains
     * @param bool $force
     * @return void
     * @throws \Exception
     */
    public function install(OutputInterface $output, string $magentoVersion, array $domains, bool $force): void
    {
        // === Configure required directories ===
        $mainDomain = $domains[0];
        $projectRoot = $this->getProjectRoot($mainDomain);
        // Do this here in order to be sure we have these parameters
        $this->getAuthJson();

        try {
            // Prepare installation directory
            if (!$this->filesystem->isEmptyDir($projectRoot)) {
                if ($force) {
                    $output->writeln('Cleaning up the project directory...');
                    $this->cleanUp($projectRoot);
                    $this->filesystem->getDirPath($projectRoot);
                    // getcwd() return false after cleanup, because original dir is deleted
                    chdir($projectRoot);
                } else {
                    // Unset variable so that project files are not removed in this particular case
                    unset($mainDomain);
                    throw new InstallationDirectoryNotEmptyException(<<<EOF
                    Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
                    Stop all containers (if any), remove the folder and re-run setup.
                    You can also use '-f' option to force install Magento with this domain.
                    EOF);
                }
            }

            // === 1. Dockerize ===
            $output->writeln('Generating composition files and running it...');
            $webRoot = $this->composition->getParameterValue('web_root');
            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDirPath($projectRoot . ltrim($webRoot, '\\/'));
            // @TODO: must be done while dumping composition and processing virtual hosts file
            $this->filesystem->getDirPath($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');
            $modificationContext = $this->composition->dump($output, $projectRoot, false);
            $dockerComposeDir = $modificationContext->getDockerComposeDir();
            $dockerCompose = $this->dockerCompose->setCwd($dockerComposeDir);
            // just in case previous setup was not successful
            $dockerCompose->down();
            $dockerCompose->up(true, true);
            $phpContainerName = $dockerCompose->getServiceContainerName(self::PHP_SERVICE);
            $phpContainer = $this->phpContainer->setContainerName($phpContainerName);

            // For testing with composer packages cache
            //$this->shell->run(
            //    "docker exec -u root $phpContainerName sh -c 'chown -R docker:docker /home/docker/.composer'"
            //);
            $output->writeln('Setting composer to trust Magento composer plugins...');

            foreach (self::ALLOWED_PLUGINS as $plugin) {
                // Redirect output to /dev/null to suppress errors from the early Composer versions
                $this->docker->run(
                    "composer config --global --no-interaction allow-plugins.$plugin true 1>/dev/null 2>/dev/null",
                    $phpContainerName
                );
            }

            // === 2. Create Magento project ===
            $process = $this->docker->mustRun('composer -V', $phpContainerName, 60, false);
            $composerMeta = trim($process->getOutput(), '');
            $composerVersion = (int) preg_replace('/\D/', '', $composerMeta)[0] === 1 ? 1 : 2;
            $configuredAuthJson = $this->getAuthJson($composerVersion);

            // Must write project files to /var/www/html/project/ and move files to the WORKDIR
            // This is required because `.dockerizer` dir is present and can be deleted due to mounted files there
            $magentoRepositoryUrl = sprintf(
                self::MAGENTO_REPOSITORY,
                $configuredAuthJson['http-basic']['repo.magento.com']['username'],
                $configuredAuthJson['http-basic']['repo.magento.com']['password']
            );

            // A workaround so that we do not have too high memory limit in PHP containers with old PHP versions
            if (
                Comparator::lessThan($magentoVersion, '2.2.0')
                && Comparator::lessThan($phpContainer->getPhpVersion(), '7.1')
            ) {
                $composerPharUrl = self::COMPOSER_1_DOWNLOAD_URL;
                $this->docker->mustRun("curl $composerPharUrl --output composer.phar 2>/dev/null", $phpContainerName);
                $composer = 'php -d memory_limit=4G composer.phar';
            } else {
                $composer = 'composer';
            }

            $magentoCreateProject = sprintf(
                '%s create-project %s --repository=%s %s=%s /var/www/html/project/',
                $composer,
                $output->isQuiet() ? '-q' : '',
                $magentoRepositoryUrl,
                self::MAGENTO_PROJECT,
                $magentoVersion
            );
            $output->writeln('Calling "composer create-project" to get project files...');

            // Just run, because composer returns warnings to the error stream. We will anyway fail later
            $this->docker->run($magentoCreateProject, $phpContainerName, Shell::EXECUTION_TIMEOUT_LONG);

            if (
                Comparator::lessThan($magentoVersion, '2.2.0')
                && Comparator::lessThan($phpContainer->getPhpVersion(), '7.1')
            ) {
                $this->docker->run('rm composer.phar', $phpContainerName);
            }

            // Move files to the WORKDIR. Note that `/var/www/html/var/` is not empty, so `mv` can't move its content
            $this->docker->mustRun('cp -r /var/www/html/project/var/ /var/www/html/', $phpContainerName);
            $this->docker->mustRun('rm -rf /var/www/html/project/var/', $phpContainerName);
            $this->docker->mustRun(
                'sh -c \'ls -A -1 /var/www/html/project/ | xargs -I {} mv -f /var/www/html/project/{} /var/www/html/\'',
                $phpContainerName
            );
            $this->docker->mustRun('rmdir /var/www/html/project/', $phpContainerName);

            // === 3. Initialize Git repository ===
            $output->writeln('Initializing repository with Magento 2 files...');
            // Hotfix for Magento 2.4.1
            // @TODO: install 2.4.1 and test this, check patches
            if (!file_exists("$projectRoot.gitignore")) {
                $this->addGitignoreFrom240($projectRoot);
            }

            $this->shell->mustRun('git init');
            $this->shell->mustRun('git config core.fileMode false');

            // Set username if not is set globally
            if (!$this->shell->run('git config user.name')->isSuccessful()) {
                $this->shell->mustRun('git config user.name Dockerizer');
                $output->writeln('<info>Set git user.name for this repository!</info>');
            }

            // Set user email if not is set globally
            if (!$this->shell->run('git config user.email')->isSuccessful()) {
                $this->shell->mustRun('git config user.email email@example.com');
                $output->writeln('<info>Set git user.email for this repository!</info>');
            }

            $this->shell->mustRun('git add -A');
            $this->shell->mustRun('git commit -m "Initial commit" -q');

            $this->shell->mustRun('mkdir -p ./var/log/');
            $this->shell->mustRun('touch ./var/log/.gitkeep');
            $this->shell->mustRun('echo \'!/var/log/\' | tee -a .gitignore');
            $this->shell->mustRun('echo \'/var/log/*\' | tee -a .gitignore');
            $this->shell->mustRun('echo \'!/var/log/.gitkeep\' | tee -a .gitignore');

            $magentoAuthJson = $this->generateAutoJson($projectRoot, $composerVersion, $output);
            $this->filesystem->filePutContents($projectRoot . 'auth.json', $magentoAuthJson);

            // === 4. Install application ===
            $output->writeln('Docker container should be ready. Trying to install Magento...');
            $this->setupInstall($output, $dockerCompose, $mainDomain, $magentoVersion);
        } catch (InstallationDirectoryNotEmptyException | CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->writeln("<error>An error appeared during installation: {$e->getMessage()}</error>");
            $output->writeln('Cleaning up the project composition and files...');
            $this->cleanUp($projectRoot);
            throw $e;
        }

        $output->writeln('Magento installation completed!');
    }

    /**
     * @param string $dir
     * @return string
     */
    public function getProjectRoot(string $dir): string
    {
        return $this->filesystem->getDirPath($dir);
    }

    /**
     * @param OutputInterface $output
     * @param Compose $dockerCompose
     * @param string $mainDomain
     * @param string $magentoVersion
     * @return void
     * @throws \JsonException
     */
    public function setupInstall(
        OutputInterface $output,
        Compose $dockerCompose,
        string $mainDomain,
        string $magentoVersion
    ): void {
        $phpContainerName = $dockerCompose->getServiceContainerName(self::PHP_SERVICE);
        $phpContainer = $this->phpContainer->setContainerName($phpContainerName);
        $mysqlContainerName = $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE);
        $mysqlContainer = $this->mysqlContainer->setContainerName($mysqlContainerName);

        // Create user and database - can be moved to some other method
        // @TODO move this to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        $dbName = 'magento_db';
        $user = 'magento_user';
        $password = 'unsecure_password';
        $tablePrefix = 'm2_';
        $baseUrl = "https://$mainDomain/";

        $useMysqlNativePassword = $magentoVersion === '2.4.0'
            && Semver::satisfies($phpContainer->getPhpVersion(), '>=7.3 <7.4')
            && Semver::satisfies($mysqlContainer->getMysqlVersion(), '>=8.0 <8.1');

        if ($useMysqlNativePassword) {
            $createUserSql = 'CREATE USER :user@"%" IDENTIFIED WITH mysql_native_password BY :password';
        } else {
            $createUserSql = 'CREATE USER :user@"%" IDENTIFIED BY :password';
        }

        $mysqlContainer->prepareAndExecute(
            $createUserSql,
            [
                ':user' => $user,
                ':password' => $password
            ]
        );
        $mysqlContainer->exec("CREATE DATABASE `$dbName`");
        $mysqlContainer->prepareAndExecute("GRANT ALL ON `$dbName`.* TO :user@'%'", [':user' => $user]);

        // @TODO: `--backend-frontname="admin"` must be a parameter. Random name must be used by default
        $installationCommand = <<<BASH
            setup:install \
                --admin-firstname="Magento" --admin-lastname="Administrator" \
                --admin-email="email@example.com" --admin-user="development" --admin-password="q1w2e3r4" \
                --base-url="$baseUrl"  --base-url-secure="$baseUrl" \
                --db-name="$dbName" --db-user="$user" --db-password="$password" \
                --db-prefix="$tablePrefix" --db-host="mysql" \
                --use-rewrites=1 --use-secure="1" --use-secure-admin="1" \
                --session-save="files" --language=en_US --sales-order-increment-prefix="ORD$" \
                --currency=USD --timezone=America/Chicago --cleanup-database
        BASH;

        if (
            Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.0')
            && $dockerCompose->hasService(self::ELASTICSEARCH_SERVICE)
        ) {
            $installationCommand .= ' --elasticsearch-host=' . self::ELASTICSEARCH_SERVICE;
        }

        $this->runMagentoCommand(
            $installationCommand,
            $phpContainerName,
            $output->isQuiet(),
            Shell::EXECUTION_TIMEOUT_LONG
        );

        // @TODO: remove hardcoded DB name and table prefix from here
        // @TODO: maybe should wrap parameters into some container
        $this->updateMagentoConfig(
            $magentoVersion,
            $mainDomain,
            $dbName,
            $tablePrefix,
            $dockerCompose
        );

        $envPhp = include $this->getProjectRoot($mainDomain) . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);
        $environment = $this->composition->getParameterValue('environment');
        $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/{$envPhp['backend']['frontName']}/</fg=blue>
            phpMyAdmin: <fg=blue>http://pma-$environment-$mainDomain/</fg=blue> (demo only)
            MailHog: <fg=blue>http://mh-$environment-$mainDomain/</fg=blue> (demo only)
            </info>
            EOF);
    }

    /**
     * @param string $projectRoot
     * @return void
     */
    private function cleanUp(string $projectRoot): void
    {
        try {
            $dockerizerDir = $this->composition->getDockerizerDirInProject($projectRoot);

            if (is_dir($dockerizerDir)) {
                // @TODO: do not do this recursively in all directories
                foreach (Finder::create()->in($dockerizerDir)->directories() as $dockerizerDir) {
                    $dockerCompose = $this->dockerCompose->setCwd($dockerizerDir->getRealPath());

                    try {
                        $dockerCompose->down();
                    } catch (CompositionFilesNotFoundException $e) {
                        // Do nothing in case files are just missed
                    }
                }
            }

            $this->filesystem->remove([$projectRoot]);
        } catch (\Exception $e) {
            throw new CleanupException($e->getMessage());
        }
    }

    /**
     * Using native MySQL insert queries to support early Magento version which did not have a `config:set` command
     *
     * @param string $magentoVersion
     * @param string $mainDomain
     * @param string $dbName
     * @param string $tablePrefix
     * @param Compose $dockerCompose
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(
        string $magentoVersion,
        string $mainDomain,
        string $dbName,
        string $tablePrefix,
        Compose $dockerCompose
    ): void {
        $phpContainerName = $dockerCompose->getServiceContainerName(self::PHP_SERVICE);
        $mysqlContainer = $this->mysqlContainer->setContainerName(
            $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE)
        );
        $mysqlContainer->useDatabase($dbName);

        try {
            $coreConfigData = $tablePrefix . 'core_config_data';
            $insertConfig = static function (string $path, string|int $value) use ($mysqlContainer, $coreConfigData) {
                $mysqlContainer->prepareAndExecute(
                    sprintf(
                        "INSERT INTO `%s` (`scope`, `scope_id`, `path`, `value`) VALUES ('default', 0, :path, :value)",
                        $coreConfigData
                    ),
                    [
                        ':path'  => $path,
                        ':value' => $value
                    ]
                );
            };

            // @TODO: move checking services availability to `docker-compose up`
            if (
                Comparator::lessThan($magentoVersion, '2.4.0')
                && $dockerCompose->hasService(self::ELASTICSEARCH_SERVICE)
            ) {
                $elasticsearchContainerName = $dockerCompose->getServiceContainerName(self::ELASTICSEARCH_SERVICE);

                // Some Elasticsearch containers have `curl`, some have `wget`...
                try {
                    $process = $this->docker->mustRun(
                        'wget -q -O - http://localhost:9200', // no curl, but wget is installed
                        $elasticsearchContainerName,
                        10,
                        false
                    );
                } catch (ProcessFailedException) {
                    $process = $this->docker->mustRun(
                        'curl -XGET http://localhost:9200', // try curl if failed
                        $elasticsearchContainerName,
                        10,
                        false
                    );
                }

                $elasticsearchMeta = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $elasticsearchMajorVersion = (int) $elasticsearchMeta['version']['number'];
                $insertConfig(
                    "catalog/search/elasticsearch{$elasticsearchMajorVersion}_server_hostname",
                    'elasticsearch'
                );
                $insertConfig('catalog/search/engine', "elasticsearch$elasticsearchMajorVersion");
            }

            // There is no entry point in the project root as of Magento 2.4.2
            if (Comparator::lessThan($magentoVersion, '2.4.2')) {
                $insertConfig('web/unsecure/base_static_url', "https://$mainDomain/static/");
                $insertConfig('web/unsecure/base_media_url', "https://$mainDomain/media/");
                $insertConfig('web/secure/base_static_url', "https://$mainDomain/static/");
                $insertConfig('web/secure/base_media_url', "https://$mainDomain/media/");
            }

            $insertConfig('dev/static/sign', 0);
            $insertConfig('dev/js/move_script_to_bottom', 1);
            $insertConfig('dev/css/use_css_critical_path', 1);

            if ($dockerCompose->hasService(self::VARNISH_SERVICE)) {
                $varnishPort = $this->composition->getParameterValue('varnish_port');
                $this->runMagentoCommand(
                    'setup:config:set --http-cache-hosts=varnish-cache:' . $varnishPort,
                    $phpContainerName,
                    true
                );

                $insertConfig('system/full_page_cache/caching_application', 2);
                $insertConfig('system/full_page_cache/varnish/access_list', 'localhost,php');
                $insertConfig('system/full_page_cache/varnish/backend_host', 'php');
                $insertConfig('system/full_page_cache/varnish/backend_port', 80);
                $insertConfig('system/full_page_cache/varnish/grace_period', 300);
            }
        } catch (\Exception $e) {
            $mysqlContainer->unUseDatabase();
            throw $e;
        }

        $mysqlContainer->unUseDatabase();
        $this->runMagentoCommand('cache:clean', $phpContainerName, true);
    }

    /**
     * @param string $projectRoot
     * @param int $composerVersion
     * @param OutputInterface $output
     * @return string
     * @throws \JsonException
     */
    private function generateAutoJson(string $projectRoot, int $composerVersion, OutputInterface $output): string
    {
        $authJson = $this->getAuthJson($composerVersion);
        // Skip everything that is not needed for Magento
        $magentoAuthJson = [
            'http-basic' => [
                'repo.magento.com' => [
                    'username' => $authJson['http-basic']['repo.magento.com']['username'],
                    'password' => $authJson['http-basic']['repo.magento.com']['password']
                ]
            ]
        ];

        $composeLock = json_decode(
            $this->filesystem->fileGetContents($projectRoot . 'composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $composerPackageMeta = array_filter(
            $composeLock['packages'],
            static fn ($item) => $item['name'] === 'composer/composer'
        );
        $composerVersion = array_values($composerPackageMeta)[0]['version'];

        // https://support.magento.com/hc/en-us/articles/4402562382221-Github-token-issue-and-Composer-key-procedures
        // @TODO: 2.3.7 > 1.10.20; check with Magento 2.3.7
        if (Comparator::greaterThanOrEqualTo($composerVersion, '1.10.21')) {
            $magentoAuthJson['github-oauth']['github.com'] = $authJson['github-oauth']['github.com'];
        } else {
            $output->writeln(
                'Skip adding github.com oAuth token, because new tokens are not supported by Composer prior to 1.10.21'
            );
        }

        return json_encode($magentoAuthJson, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param int $composerVersion
     * @return array
     * @throws \JsonException
     */
    private function getAuthJson(int $composerVersion = 1): array
    {
        $authJson = $this->filesystem->getAuthJson();

        if (
            !isset(
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password'],
                $authJson['github-oauth']['github.com'],
            )
        ) {
            throw new \RuntimeException(
                'The file "auth.json" does not contain "username" or "password" for "repo.magento.com",' .
                ' and a GitHub key!'
            );
        }

        // if composer version === 1 - remove `ghp_` from the key
        if ($composerVersion === 1) {
            $authJson['github-oauth']['github.com'] = explode('_', $authJson['github-oauth']['github.com'])[1];
        }

        return $authJson;
    }

    /**
     * @param string $command
     * @param string $phpContainerName
     * @param bool $isQuite
     * @param float|null $timeout
     * @return void
     */
    private function runMagentoCommand(
        string $command,
        string $phpContainerName,
        bool $isQuite,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT
    ): void {
        $fullCommand = 'php bin/magento ';
        $fullCommand .= $isQuite ? '-q ' : '';
        $fullCommand .= $command;

        $this->docker->mustRun($fullCommand, $phpContainerName, $timeout);
    }

    /**
     * @param string $projectRoot
     */
    private function addGitignoreFrom240(string $projectRoot): void
    {
        file_put_contents(
            "$projectRoot.gitignore",
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
