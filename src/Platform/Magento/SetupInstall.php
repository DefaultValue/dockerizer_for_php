<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Platform\Magento\Exception\MagentoNotInstalledException;
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
        $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
        $appContainers = $this->magento->initialize($dockerCompose, $projectRoot);
        // Get data `$this->composition` during installation, get from app/etc/env.php otherwise
        // Must save this data BEFORE we reinstall Magento and erase the original app/etc/env.php file
        $httpCacheHost = '';

        try {
            $env = $this->magento->getEnvPhp($projectRoot);
            $httpCacheHost = isset($env['http_cache_hosts'])
                ? $env['http_cache_hosts'][0]['host'] . ':' . $env['http_cache_hosts'][0]['port']
                : '';
            $mainDomain = $appContainers->getMainDomain();
            $appContainers->runMagentoCommand('cache:clean', true);
            $appContainers->runMagentoCommand('cache:flush', true);
            unset($env);
        } catch (MagentoNotInstalledException) {
            if ($dockerCompose->hasService(AppContainers::VARNISH_SERVICE)) {
                $httpCacheHost = 'varnish-cache:' . $this->composition->getParameterValue('varnish_port');
            }

            $domains = (string) $this->composition->getParameterValue('domains');
            $mainDomain = explode(' ', $domains)[0];
        }

        $baseUrl = "https://$mainDomain/";
        /** @var Mysql $mysqlService */
        $mysqlService = $appContainers->getService(AppContainers::MYSQL_SERVICE);
        $magentoVersion = $appContainers->getMagentoVersion();

        $dbName = $mysqlService->getMysqlDatabase();
        $dbUser = $mysqlService->getMysqlUser();
        $dbPassword = escapeshellarg($mysqlService->getMysqlPassword());
        $tablePrefix = $mysqlService->getTablePrefix();

        // @TODO: `--backend-frontname="admin"` must be a parameter. Random name must be used by default
        $escapedAdminPassword = escapeshellarg('q1w2e3r4');
        $installationCommand = <<<BASH
            setup:install \
                --admin-firstname='Magento' --admin-lastname='Administrator' \
                --admin-email='email@example.com' --admin-user='development' --admin-password=$escapedAdminPassword \
                --base-url=$baseUrl  --base-url-secure=$baseUrl \
                --db-name=$dbName --db-user='$dbUser' --db-password=$dbPassword \
                --db-prefix=$tablePrefix --db-host=mysql \
                --use-rewrites=1 --use-secure=1 --use-secure-admin="1" \
                --session-save=files --language=en_US --sales-order-increment-prefix='ORD$' \
                --currency=USD --timezone=America/Chicago --cleanup-database
        BASH;

        if (
            Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.0')
            && $appContainers->hasService(AppContainers::ELASTICSEARCH_SERVICE)
        ) {
            $installationCommand .= ' --elasticsearch-host=' . AppContainers::ELASTICSEARCH_SERVICE;
        }

        $appContainers->runMagentoCommand(
            $installationCommand,
            $output->isQuiet(),
            Shell::EXECUTION_TIMEOUT_LONG,
            // Setting `tty` to `!isQuiet`. Other Composer always outputs extra unneeded data with `setup:install`
            !$output->isQuiet()
        );
        $this->updateMagentoConfig($appContainers, $httpCacheHost, $output->isQuiet());

        $env = $this->magento->getEnvPhp($projectRoot);
        $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/{$env['backend']['frontName']}/</fg=blue>
            </info>
            EOF);
    }

    /**
     * Using native MySQL insert queries to support early Magento version which did not have a `config:set` command
     *
     * @param AppContainers $appContainers
     * @param string $httpCacheHost
     * @param bool $isQuiet
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(
        AppContainers $appContainers,
        string $httpCacheHost = '',
        bool $isQuiet = false
    ): void {
        $mainDomain = $appContainers->getMainDomain();
        $magentoVersion = $appContainers->getMagentoVersion();

        // @TODO: move checking services availability to `docker-compose up`
        if (
            Comparator::lessThan($magentoVersion, '2.4.0')
            && $appContainers->hasService(AppContainers::ELASTICSEARCH_SERVICE)
        ) {
            /** @var Elasticsearch $elasticsearchService */
            $elasticsearchService = $appContainers->getService(AppContainers::ELASTICSEARCH_SERVICE);
            $elasticsearchMeta = $elasticsearchService->getMeta();
            $elasticsearchMajorVersion = (int) $elasticsearchMeta['version']['number'];
            $appContainers->insertConfig(
                "catalog/search/elasticsearch{$elasticsearchMajorVersion}_server_hostname",
                'elasticsearch'
            );
            $appContainers->insertConfig('catalog/search/engine', "elasticsearch$elasticsearchMajorVersion");
        }

        // There is no entry point in the project root as of Magento 2.4.2
        if (Comparator::lessThan($magentoVersion, '2.4.2')) {
            $appContainers->insertConfig('web/unsecure/base_static_url', "https://$mainDomain/static/");
            $appContainers->insertConfig('web/unsecure/base_media_url', "https://$mainDomain/media/");
            $appContainers->insertConfig('web/secure/base_static_url', "https://$mainDomain/static/");
            $appContainers->insertConfig('web/secure/base_media_url', "https://$mainDomain/media/");
        }

        $appContainers->insertConfig('dev/static/sign', 1);
        $appContainers->insertConfig('dev/js/move_script_to_bottom', 1);
        $appContainers->insertConfig('dev/css/use_css_critical_path', 1);

        if ($httpCacheHost) {
            $appContainers->runMagentoCommand('setup:config:set --http-cache-hosts=' . $httpCacheHost, $isQuiet);
            $appContainers->insertConfig('system/full_page_cache/caching_application', 2);
            $appContainers->insertConfig('system/full_page_cache/varnish/access_list', 'localhost,php');
            $appContainers->insertConfig('system/full_page_cache/varnish/backend_host', 'php');
            $appContainers->insertConfig('system/full_page_cache/varnish/backend_port', 80);
            $appContainers->insertConfig('system/full_page_cache/varnish/grace_period', 300);
        }

        $appContainers->runMagentoCommand('cache:clean', $isQuiet);
        $appContainers->runMagentoCommand('cache:flush', $isQuiet);
    }
}
