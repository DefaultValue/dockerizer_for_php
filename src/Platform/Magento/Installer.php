<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use DefaultValue\Dockerizer\Docker\Compose\CompositionFilesNotFoundException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

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

    // private const EXECUTION_TIMEOUT_MEDIUM = 300; - unused for now

    private const EXECUTION_TIMEOUT_LONG = 3600;

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
            $webRoot = $this->composition->getParameterValue('web_root', true);
            // Web root is not available on the first dockerization before actually installing Magento - create it
            $this->filesystem->getDirPath($projectRoot . ltrim($webRoot, '\\/'));
            // @TODO: must be done while dumping composition and processing virtual hosts file
            $this->filesystem->getDirPath($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');
            $modificationContext = $this->composition->dump($output, $projectRoot, false);
            $dockerComposeDir = $modificationContext->getDockerComposeDir();
            $dockerCompose = $this->dockerCompose->setCwd($dockerComposeDir);
            // just in case previous setup was not successful
            $dockerCompose->down();
            $dockerCompose->up();

            // === 2. Create Magento project ===
            $phpContainerName = $dockerCompose->getServiceContainerName(self::PHP_SERVICE);
            $process = $this->docker->mustRun('composer -V', $phpContainerName);
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
            $magentoCreateProject = sprintf(
                'composer create-project --repository=%s %s=%s /var/www/html/project/',
                $magentoRepositoryUrl,
                self::MAGENTO_PROJECT,
                $magentoVersion
            );
            $output->writeln('Calling "composer create-project" to get project files...');
            // Just run, because composer returns warnings to the error stream. We will anyway fail later
            $this->docker->run($magentoCreateProject, $phpContainerName, self::EXECUTION_TIMEOUT_LONG);

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
            $mysqlContainerName = $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE);
            $this->setupInstall($phpContainerName, $mysqlContainerName, $mainDomain, $magentoVersion);

            try {
                $useVarnishCache = (bool) $dockerCompose->getServiceContainerName(self::VARNISH_SERVICE);
            } catch (\Exception $e) {
                $useVarnishCache = false;
            }

            // @TODO: remove hardcoded DB name and table prefix from here
            $this->updateMagentoConfig(
                $magentoVersion,
                $mysqlContainerName,
                'magento_db',
                'm2_',
                $mainDomain,
                $useVarnishCache
            );

            $magentoAuthJson = $this->generateAutoJson($projectRoot, $composerVersion, $output);
            $this->filesystem->filePutContents($projectRoot . 'auth.json', $magentoAuthJson);
            $envPhp = include $projectRoot . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);

            $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/{$envPhp['backend']['frontName']}/</fg=blue>
            phpMyAdmin: <fg=blue>http://pma-dev-$mainDomain/</fg=blue> (demo only)
            MailHog: <fg=blue>http://mh-dev-$mainDomain/</fg=blue> (demo only)
            </info>
            EOF);
        } catch (InstallationDirectoryNotEmptyException | CleanupException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
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
     * @param string $phpContainerName
     * @param string $mysqlContainerName
     * @param string $mainDomain
     * @param string $magentoVersion
     * @return void
     */
    private function setupInstall(
        string $phpContainerName,
        string $mysqlContainerName,
        string $mainDomain,
        string $magentoVersion
    ): void {
        // Create DB - can be moved to some other method
        // @TODO move this to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        $dbName = 'magento_db';
        $user = 'magento_user';
        $password = 'unsecure_password';
        $tablePrefix = 'm2_';
        $baseUrl = "https://$mainDomain/";

        $process = $this->docker->mustRun('php -r \'echo phpversion();\'', $phpContainerName);
        $phpVersion = substr($process->getOutput()[0], 0, 3);
        $useMysqlNativePassword = $magentoVersion === '2.4.0' && $phpVersion === '7.3';

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
            php bin/magento setup:install \
                --admin-firstname="Magento" --admin-lastname="Administrator" \
                --admin-email="email@example.com" --admin-user="development" --admin-password="q1w2e3r4" \
                --base-url="$baseUrl"  --base-url-secure="$baseUrl" \
                --db-name="$dbName" --db-user="$user" --db-password="$password" \
                --db-prefix="$tablePrefix" --db-host="mysql" \
                --use-rewrites=1 --use-secure="1" --use-secure-admin="1" \
                --session-save="files" --language=en_US --sales-order-increment-prefix="ORD$" \
                --currency=USD --timezone=America/Chicago --cleanup-database
        BASH;

        try {
            $this->dockerCompose->getServiceContainerName(self::ELASTICSEARCH_SERVICE);
            $installationCommand .= ' --elasticsearch-host=' . self::ELASTICSEARCH_SERVICE;
        } catch (\Exception) {
            // Do nothing if elasticsearch is not available
        }

        $this->docker->mustRun($installationCommand, $phpContainerName, self::EXECUTION_TIMEOUT_LONG);
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
        $magentoAuthJson = json_encode(
            [
                'http-basic' => [
                    'repo.magento.com' => [
                        'username' => $authJson['http-basic']['repo.magento.com']['username'],
                        'password' => $authJson['http-basic']['repo.magento.com']['password']
                    ]
                ]
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );

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
        // @TODO: 2.3.7 > 1.10.20;
        if (Comparator::greaterThanOrEqualTo($composerVersion, '1.10.21')) {
            $magentoAuthJson['github-oauth'] = [
                'github.com' => $authJson['github-oauth']['github.com']
            ];
        } else {
            $output->writeln(
                'Skip adding github.com oAuth token, because new tokens are not supported by Composer prior to 1.10.21'
            );
        }

        return json_encode($magentoAuthJson, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $sql
     * @param string $mysqlContainerName
     * @param string $dbName
     * @return void
     */
    private function runSqlInContainer(string $sql, string $mysqlContainerName, string $dbName = ''): void
    {
        $this->docker->mustRun(sprintf('mysql -uroot -proot %s -e \'%s\'', $dbName, $sql), $mysqlContainerName);
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
