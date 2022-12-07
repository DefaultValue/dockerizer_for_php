<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch;
use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;
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
        $magento = $this->magento->initialize($dockerCompose, getcwd() . DIRECTORY_SEPARATOR);
        $magento->validateIsMagento();

        // Get data `$this->composition` during installation, get from app/etc/env.php otherwise
        // Must save this data BEFORE we reinstall Magento and erase the original app/etc/env.php file
        $httpCacheHost = '';

        if ($env = $magento->getEnv(false)) {
            $httpCacheHost = isset($env['http_cache_hosts'])
                ? $env['http_cache_hosts'][0]['host'] . ':' . $env['http_cache_hosts'][0]['port']
                : '';
            $mainDomain = $magento->getMainDomain();
        } else {
            if ($dockerCompose->hasService(Magento::VARNISH_SERVICE)) {
                $httpCacheHost = 'varnish-cache:' . $this->composition->getParameterValue('varnish_port');
            }

            $domains = $this->composition->getParameterValue('domains');
            $mainDomain = explode(' ', $domains)[0];
        }

        $baseUrl = "https://$mainDomain/";
        /** @var MySQL $mysqlService */
        $mysqlService = $magento->getService(Magento::MYSQL_SERVICE);
        $magentoVersion = $magento->getMagentoVersion();

        $dbName = $mysqlService->getMySQLDatabase();
        $dbUser = $mysqlService->getMySQLUser();
        $dbPassword = escapeshellarg($mysqlService->getMySQLPassword());
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
            && $magento->hasService(Magento::ELASTICSEARCH_SERVICE)
        ) {
            $installationCommand .= ' --elasticsearch-host=' . Magento::ELASTICSEARCH_SERVICE;
        }

        $magento->runMagentoCommand(
            $installationCommand,
            $output->isQuiet(),
            Shell::EXECUTION_TIMEOUT_LONG,
            // Setting `tty` to `!isQuiet`. Other Composer always outputs extra unneeded data with `setup:install`
            !$output->isQuiet()
        );
        $this->updateMagentoConfig($magento, $httpCacheHost, $output->isQuiet());

        $envPhp = $magento->getEnv();
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
     * @param Magento $magento
     * @param string $httpCacheHost
     * @param bool $isQuiet
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(Magento $magento, string $httpCacheHost = '', bool $isQuiet = false): void
    {
        $mainDomain = $magento->getMainDomain();
        $magentoVersion = $magento->getMagentoVersion();

        // @TODO: move checking services availability to `docker-compose up`
        if (
            Comparator::lessThan($magentoVersion, '2.4.0')
            && $magento->hasService(Magento::ELASTICSEARCH_SERVICE)
        ) {
            /** @var Elasticsearch $elasticsearchService */
            $elasticsearchService = $magento->getService(Magento::ELASTICSEARCH_SERVICE);
            $elasticsearchMeta = $elasticsearchService->getMeta();
            $elasticsearchMajorVersion = (int) $elasticsearchMeta['version']['number'];
            $magento->insertConfig(
                "catalog/search/elasticsearch{$elasticsearchMajorVersion}_server_hostname",
                'elasticsearch'
            );
            $magento->insertConfig('catalog/search/engine', "elasticsearch$elasticsearchMajorVersion");
        }

        // There is no entry point in the project root as of Magento 2.4.2
        if (Comparator::lessThan($magentoVersion, '2.4.2')) {
            $magento->insertConfig('web/unsecure/base_static_url', "https://$mainDomain/static/");
            $magento->insertConfig('web/unsecure/base_media_url', "https://$mainDomain/media/");
            $magento->insertConfig('web/secure/base_static_url', "https://$mainDomain/static/");
            $magento->insertConfig('web/secure/base_media_url', "https://$mainDomain/media/");
        }

        $magento->insertConfig('dev/static/sign', 0);
        $magento->insertConfig('dev/js/move_script_to_bottom', 1);
        $magento->insertConfig('dev/css/use_css_critical_path', 1);

        if ($httpCacheHost) {
            $magento->runMagentoCommand('setup:config:set --http-cache-hosts=' . $httpCacheHost, $isQuiet);
            $magento->insertConfig('system/full_page_cache/caching_application', 2);
            $magento->insertConfig('system/full_page_cache/varnish/access_list', 'localhost,php');
            $magento->insertConfig('system/full_page_cache/varnish/backend_host', 'php');
            $magento->insertConfig('system/full_page_cache/varnish/backend_port', 80);
            $magento->insertConfig('system/full_page_cache/varnish/grace_period', 300);
        }

        $magento->runMagentoCommand('cache:clean', $isQuiet);
        $magento->runMagentoCommand('cache:flush', $isQuiet);
    }
}
