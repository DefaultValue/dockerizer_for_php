<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch;
use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Php;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Shell\Shell;
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

        // @TODO move this to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        // @TODO: maybe should wrap parameters into some DTO
        $dbName = 'magento_db';
        $user = 'magento_user';
        $dbPassword = 'un\'""$%!secure_passwo%%$&rd';
        $tablePrefix = 'm2_';

        // Get data `$this->composition` during installation, get from app/etc/env.php otherwise
        // Must save this data BEFORE we reinstall Magento and erase the original app/etc/env.php file
        $httpCacheHost = '';

        if ($env = $this->magento->getEnv(false)) {
            $httpCacheHost = isset($env['http_cache_hosts'])
                ? $env['http_cache_hosts'][0]['host'] . ':' . $env['http_cache_hosts'][0]['port']
                : '';
            $mainDomain = $this->magento->getMainDomain();
        } else {
            if ($dockerCompose->hasService(Magento::VARNISH_SERVICE)) {
                $httpCacheHost = 'varnish-cache:' . $this->composition->getParameterValue('varnish_port');
            }

            $domains = $this->composition->getParameterValue('domains');
            $mainDomain = substr($domains, 0, strpos($domains, ' '));
        }

        $baseUrl = "https://$mainDomain/";
        /** @var Php $phpService */
        $phpService = $this->magento->getService(Magento::PHP_SERVICE);
        /** @var MySQL $mysqlService */
        $mysqlService = $this->magento->getService(Magento::MYSQL_SERVICE);
        $magentoVersion = $this->magento->getMagentoVersion();

        // Try dropping ser first, because MySQL <5.7.6 does not support `CREATE USER IF NOT EXISTS`
        try {
            $mysqlService->prepareAndExecute(
                'DROP USER :user@"%"',
                [
                    ':user' => $user
                ]
            );
        } catch (\PDOException) {
        }

        $useMysqlNativePassword = $magentoVersion === '2.4.0'
            && Semver::satisfies($phpService->getPhpVersion(), '>=7.3 <7.4')
            && Semver::satisfies($mysqlService->getMysqlVersion(), '>=8.0 <8.1');

        if ($useMysqlNativePassword) {
            $createUserSql = 'CREATE USER :user@"%" IDENTIFIED WITH mysql_native_password BY :password';
        } else {
            $createUserSql = 'CREATE USER :user@"%" IDENTIFIED BY :password';
        }

        $mysqlService->prepareAndExecute(
            $createUserSql,
            [
                ':user' => $user,
                ':password' => $dbPassword
            ]
        );
        $mysqlService->exec("CREATE DATABASE IF NOT EXISTS `$dbName`");
        $mysqlService->prepareAndExecute(
            "GRANT ALL ON `$dbName`.* TO :user@'%'",
            [
                ':user' => $user
            ]
        );

        // @TODO: `--backend-frontname="admin"` must be a parameter. Random name must be used by default
        $escapedAdminPassword = escapeshellarg('q1w2e3r4');
        $escapedDbPassword = escapeshellarg($dbPassword);
        $installationCommand = <<<BASH
            setup:install \
                --admin-firstname='Magento' --admin-lastname='Administrator' \
                --admin-email='email@example.com' --admin-user='development' --admin-password=$escapedAdminPassword \
                --base-url=$baseUrl  --base-url-secure=$baseUrl \
                --db-name=$dbName --db-user='$user' --db-password=$escapedDbPassword \
                --db-prefix=$tablePrefix --db-host=mysql \
                --use-rewrites=1 --use-secure=1 --use-secure-admin="1" \
                --session-save=files --language=en_US --sales-order-increment-prefix='ORD$' \
                --currency=USD --timezone=America/Chicago --cleanup-database
        BASH;

        if (
            Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.0')
            && $this->magento->hasService(Magento::ELASTICSEARCH_SERVICE)
        ) {
            $installationCommand .= ' --elasticsearch-host=' . Magento::ELASTICSEARCH_SERVICE;
        }

        // DB and env file was just created during installation. Det the service again if needed here
        unset($mysqlService);
        $this->magento->runMagentoCommand(
            $installationCommand,
            $output->isQuiet(),
            Shell::EXECUTION_TIMEOUT_LONG
        );
        $this->updateMagentoConfig($httpCacheHost);

        $envPhp = $this->magento->getEnv();
        $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/{$envPhp['backend']['frontName']}/</fg=blue>
            </info>
            EOF);
    }

    /**
     * Using native MySQL insert queries to support early Magento version which did not have a `config:set` command
     *
     * @param string $httpCacheHost
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(string $httpCacheHost = ''): void
    {
        $mainDomain = $this->magento->getMainDomain();
        $magentoVersion = $this->magento->getMagentoVersion();

        // @TODO: move checking services availability to `docker-compose up`
        if (
            Comparator::lessThan($magentoVersion, '2.4.0')
            && $this->magento->hasService(Magento::ELASTICSEARCH_SERVICE)
        ) {
            /** @var Elasticsearch $elasticsearchService */
            $elasticsearchService = $this->magento->getService(Magento::ELASTICSEARCH_SERVICE);
            $elasticsearchMeta = $elasticsearchService->getMeta();
            $elasticsearchMajorVersion = (int) $elasticsearchMeta['version']['number'];
            $this->magento->insertConfig(
                "catalog/search/elasticsearch{$elasticsearchMajorVersion}_server_hostname",
                'elasticsearch'
            );
            $this->magento->insertConfig('catalog/search/engine', "elasticsearch$elasticsearchMajorVersion");
        }

        // There is no entry point in the project root as of Magento 2.4.2
        if (Comparator::lessThan($magentoVersion, '2.4.2')) {
            $this->magento->insertConfig('web/unsecure/base_static_url', "https://$mainDomain/static/");
            $this->magento->insertConfig('web/unsecure/base_media_url', "https://$mainDomain/media/");
            $this->magento->insertConfig('web/secure/base_static_url', "https://$mainDomain/static/");
            $this->magento->insertConfig('web/secure/base_media_url', "https://$mainDomain/media/");
        }

        $this->magento->insertConfig('dev/static/sign', 0);
        $this->magento->insertConfig('dev/js/move_script_to_bottom', 1);
        $this->magento->insertConfig('dev/css/use_css_critical_path', 1);

        if ($httpCacheHost) {
            $this->magento->runMagentoCommand('setup:config:set --http-cache-hosts=' . $httpCacheHost, true);
            $this->magento->insertConfig('system/full_page_cache/caching_application', 2);
            $this->magento->insertConfig('system/full_page_cache/varnish/access_list', 'localhost,php');
            $this->magento->insertConfig('system/full_page_cache/varnish/backend_host', 'php');
            $this->magento->insertConfig('system/full_page_cache/varnish/backend_port', 80);
            $this->magento->insertConfig('system/full_page_cache/varnish/grace_period', 300);
        }

        $this->magento->runMagentoCommand('cache:clean', true);
        $this->magento->runMagentoCommand('cache:flush', true);
    }
}
