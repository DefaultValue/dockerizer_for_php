<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use DefaultValue\Dockerizer\Console\Shell\Shell;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch;
use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Php;
use DefaultValue\Dockerizer\Platform\Magento;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install Magento.
 * Run this only in the project root dir! Use `chdir()` before usage if needed.
 */
class SetupInstall
{
    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Platform\Magento $magento,
    ) {
    }

    /**
     * @param OutputInterface $output
     * @param Compose $dockerCompose
     * @return void
     * @throws \JsonException
     */
    public function setupInstall(
        OutputInterface $output,
        Compose $dockerCompose
    ): void {
        $this->magento = $this->magento->initialize($dockerCompose, getcwd() . DIRECTORY_SEPARATOR);
        $this->magento->validateIsMagento();
$mainDomain = $this->magento->getMainDomain();

        // Create user and database - can be moved to some other method
        // @TODO move this to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        $dbName = 'magento_db';
        $user = 'magento_user';
        $password = 'unsecure_password';
        $tablePrefix = 'm2_';
        $baseUrl = "https://$mainDomain/";
        /** @var Php $phpService */
        $phpService = $this->magento->getService(Magento::PHP_SERVICE);
        /** @var MySQL $mysqlService */
        $mysqlService = $this->magento->getService(Magento::MYSQL_SERVICE);
        $magentoVersion = $this->magento->getMagentoVersion();

        $useMysqlNativePassword = $magentoVersion === '2.4.0'
            && Semver::satisfies($phpService->getPhpVersion(), '>=7.3 <7.4')
            && Semver::satisfies($mysqlService->getMysqlVersion(), '>=8.0 <8.1');

        if ($useMysqlNativePassword) {
            $createUserSql = 'CREATE USER IF NOT EXISTS :user@"%" IDENTIFIED WITH mysql_native_password BY :password';
        } else {
            $createUserSql = 'CREATE USER IF NOT EXISTS :user@"%" IDENTIFIED BY :password';
        }

        $mysqlService->prepareAndExecute(
            $createUserSql,
            [
                ':user' => $user,
                ':password' => $password
            ]
        );
        $mysqlService->exec("DROP DATABASE IF EXISTS `$dbName`");
        $mysqlService->exec("CREATE DATABASE `$dbName`");
        $mysqlService->prepareAndExecute(
            "GRANT ALL ON `$dbName`.* TO :user@'%'",
            [
                ':user' => $user
            ]
        );

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
            && $this->magento->hasService(Magento::ELASTICSEARCH_SERVICE)
        ) {
            $installationCommand .= ' --elasticsearch-host=' . Magento::ELASTICSEARCH_SERVICE;
        }

        $this->magento->runMagentoCommand(
            $installationCommand,
            $output->isQuiet(),
            Shell::EXECUTION_TIMEOUT_LONG
        );

        // @TODO: remove hardcoded DB name and table prefix from here
        // @TODO: maybe should wrap parameters into some container
        $this->updateMagentoConfig(
            $mainDomain,
            $dbName,
            $tablePrefix
        );

        $envPhp = $this->magento->getEnv();
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
     * Using native MySQL insert queries to support early Magento version which did not have a `config:set` command
     *
     * @param string $mainDomain
     * @param string $dbName
     * @param string $tablePrefix
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(
        string $mainDomain,
        string $dbName,
        string $tablePrefix,
    ): void {
        $magentoVersion = $this->magento->getMagentoVersion();
        /** @var MySQL $mysqlService */
        $mysqlService = $this->magento->getService(Magento::MYSQL_SERVICE);
        $mysqlService->useDatabase($dbName);

        try {
            $coreConfigData = $tablePrefix . 'core_config_data';
            $insertConfig = static function (string $path, string|int $value) use ($mysqlService, $coreConfigData) {
                $mysqlService->prepareAndExecute(
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
                && $this->magento->hasService(Magento::ELASTICSEARCH_SERVICE)
            ) {
                /** @var Elasticsearch $elasticsearchService */
                $elasticsearchService = $this->magento->getService(Magento::ELASTICSEARCH_SERVICE);
                $elasticsearchMeta = $elasticsearchService->getMeta();
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

            if ($this->magento->hasService(Magento::VARNISH_SERVICE)) {
                $varnishPort = $this->composition->getParameterValue('varnish_port');
                $this->magento->runMagentoCommand(
                    'setup:config:set --http-cache-hosts=varnish-cache:' . $varnishPort,
                    true
                );

                $insertConfig('system/full_page_cache/caching_application', 2);
                $insertConfig('system/full_page_cache/varnish/access_list', 'localhost,php');
                $insertConfig('system/full_page_cache/varnish/backend_host', 'php');
                $insertConfig('system/full_page_cache/varnish/backend_port', 80);
                $insertConfig('system/full_page_cache/varnish/grace_period', 300);
            }
        } catch (\Exception $e) {
            $mysqlService->unUseDatabase();
            throw $e;
        }

        $mysqlService->unUseDatabase();
        $this->magento->runMagentoCommand('cache:clean', true);
        $this->magento->runMagentoCommand('cache:flush', true);
    }
}
