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
use Symfony\Component\Process\Process;

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
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell
    ) {
    }

    /**
     * @TODO: split into several methods
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
                && Comparator::lessThan($this->getPhpVersion($phpContainerName), '7.1')
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
                && Comparator::lessThan($this->getPhpVersion($phpContainerName), '7.1')
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
            if (!file_exists("{$projectRoot}.gitignore")) {
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

            // === 4. Install application ===
            $output->writeln('Docker container should be ready. Trying to install Magento...');
            $this->setupInstall($output, $dockerCompose, $mainDomain, $magentoVersion);

            if ($useVarnishCache = $dockerCompose->hasService(self::VARNISH_SERVICE)) {
                $varnishPort = $this->composition->getParameterValue('varnish_port');
                $this->runMagentoCommand(
                    'setup:config:set --http-cache-hosts=varnish-cache:' . $varnishPort,
                    $phpContainerName,
                    $output
                );
            }

            // @TODO: remove hardcoded DB name and table prefix from here
            // @TODO: maybe should wrap parameters into some container
            $this->updateMagentoConfig(
                $magentoVersion,
                $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE),
                'magento_db',
                'm2_',
                $mainDomain,
                $useVarnishCache
            );
            $this->runMagentoCommand('cache:clean', $phpContainerName, $output);

            $magentoAuthJson = $this->generateAutoJson($projectRoot, $composerVersion, $output);
            $this->filesystem->filePutContents($projectRoot . 'auth.json', $magentoAuthJson);
            $envPhp = include $projectRoot . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);

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
     * @param OutputInterface $output
     * @param Compose $dockerCompose
     * @param string $mainDomain
     * @param string $magentoVersion
     * @return void
     * @throws \JsonException
     */
    private function setupInstall(
        OutputInterface $output,
        Compose $dockerCompose,
        string $mainDomain,
        string $magentoVersion
    ): void {
        $phpContainerName = $dockerCompose->getServiceContainerName(self::PHP_SERVICE);
        $mysqlContainerName = $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE);

        // Create DB - can be moved to some other method
        // @TODO move this to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        $dbName = 'magento_db';
        $user = 'magento_user';
        $password = 'unsecure_password';
        $tablePrefix = 'm2_';
        $baseUrl = "https://$mainDomain/";
        $phpVersion = $this->getPhpVersion($phpContainerName);

        // @TODO: wait till MySQL it ready! Currently that a facepalm like `set_timeout` in JS
        // Service check after docker-compose up must be implemented for all services
        $retries = 15;

        while ($retries--) {
            try {
                $process = $this->runSqlInContainer('SELECT VERSION();', $mysqlContainerName, '', false);
                $mysqlVersion = explode('-', trim($process->getOutput()))[0];
                break;
            } catch (\Exception) {
                sleep(1);
            }
        }

        if (!isset($mysqlVersion)) {
            throw new \RuntimeException('Can\'t get MySQL version! The services may e not running.');
        }

        $useMysqlNativePassword = $magentoVersion === '2.4.0'
            && $phpVersion === '7.3'
            && Semver::satisfies($mysqlVersion, '>=8.0 <8.1');

        if ($useMysqlNativePassword) {
            $createUserSql = "CREATE USER \"$user\"@\"%\" IDENTIFIED WITH mysql_native_password BY \"$password\"";
        } else {
            $createUserSql =  "CREATE USER \"$user\"@\"%\" IDENTIFIED BY \"$password\"";
        }

        $this->runSqlInContainer($createUserSql, $mysqlContainerName);
        $this->runSqlInContainer("CREATE DATABASE $dbName", $mysqlContainerName);
        // @TODO: can we somehow limit the host access by name?
        $this->runSqlInContainer("GRANT ALL ON $dbName.* TO \"$user\"@\"%\"", $mysqlContainerName);

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

        $this->runMagentoCommand($installationCommand, $phpContainerName, $output, Shell::EXECUTION_TIMEOUT_LONG);

        // @TODO: move to `updateMagentoConfig()`
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

            $this->runMagentoCommand(
                "config:set catalog/search/elasticsearch{$elasticsearchMajorVersion}_server_hostname elasticsearch",
                $phpContainerName,
                $output
            );

            $this->runMagentoCommand(
                "config:set catalog/search/engine elasticsearch$elasticsearchMajorVersion",
                $phpContainerName,
                $output
            );
        }
    }

    /**
     * @param string $phpContainerName
     * @return string
     */
    private function getPhpVersion(string $phpContainerName): string
    {
        $process = $this->docker->mustRun('php -r \'echo phpversion();\'', $phpContainerName, 60, false);
        return substr($process->getOutput(), 0, 3);
    }

    /**
     * @param string $magentoVersion
     * @param string $mysqlContainerName
     * @param string $dbName
     * @param string $tablePrefix
     * @param string $mainDomain
     * @param bool $useVarnishCache
     * @return void
     */
    private function updateMagentoConfig(
        string $magentoVersion,
        string $mysqlContainerName,
        string $dbName,
        string $tablePrefix,
        string $mainDomain,
        bool $useVarnishCache = false
    ): void {
        $insert = function (array $data) use ($mysqlContainerName, $dbName, $tablePrefix) {
            $columns = '`' . implode('`, `', array_keys($data)) . '`';
            $values = '"' . implode('", "', array_values($data)) . '"';
            $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $tablePrefix . 'core_config_data', $columns, $values);
            $this->runSqlInContainer($sql, $mysqlContainerName, $dbName);
        };

        // There is no entry point in the project root as of Magento 2.4.3
        if (Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.3')) {
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/unsecure/base_static_url',
                'value'    => "https://$mainDomain/static/"
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/unsecure/base_media_url',
                'value'    => "https://$mainDomain/media/"
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/secure/base_static_url',
                'value'    => "https://$mainDomain/static/"
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/secure/base_media_url',
                'value'    => "https://$mainDomain/media/"
            ]);
        }

        $insert([
            'scope'    => 'default',
            'scope_id' => 0,
            'path'     => 'dev/static/sign',
            'value'    => 1
        ]);
        $insert([
            'scope'    => 'default',
            'scope_id' => 0,
            'path'     => 'dev/js/move_script_to_bottom',
            'value'    => 1
        ]);
        $insert([
            'scope'    => 'default',
            'scope_id' => 0,
            'path'     => 'dev/css/use_css_critical_path',
            'value'    => 1
        ]);

        if ($useVarnishCache) {
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'system/full_page_cache/caching_application',
                'value'    => 2
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'system/full_page_cache/varnish/access_list',
                'value'    => 'localhost,php'
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'system/full_page_cache/varnish/backend_host',
                'value'    => 'php'
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'system/full_page_cache/varnish/backend_port',
                'value'    => 80
            ]);
            $insert([
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'system/full_page_cache/varnish/grace_period',
                'value'    => 300
            ]);
        }
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
     * @TODO: maybe should find container's IP and connect via PDO instead of this dirty way
     *
     * @param string $sql
     * @param string $mysqlContainerName
     * @param string $dbName
     * @param bool $tty
     * @return Process
     */
    private function runSqlInContainer(
        string $sql,
        string $mysqlContainerName,
        string $dbName = '',
        bool $tty = true
    ): Process {
        // escapeshellarg($sql) - ? It may contain single quotes. Not for now though
        // @TODO: pass input to /dev/stdin inside the container? Or at least use `--defaults-extra-file=` to store password
        return $this->docker->mustRun(
            sprintf('mysql -uroot -proot %s -s -e \'%s\' 2>/dev/null', $dbName, $sql),
            $mysqlContainerName,
            null,
            $tty
        );
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
     * @param OutputInterface $output
     * @param float|null $timeout
     * @return void
     */
    private function runMagentoCommand(
        string $command,
        string $phpContainerName,
        OutputInterface $output,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT
    ): void
    {
        $fullCommand = 'php bin/magento ';
        $fullCommand .= $output->isQuiet() ? '-q ' : '';
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
