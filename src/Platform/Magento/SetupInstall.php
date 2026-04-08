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
use DefaultValue\Dockerizer\Docker\ContainerizedService\Php;
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
        $appContainers = $this->magento->initialize($dockerCompose);
        // Get data `$this->composition` during installation, get from app/etc/env.php otherwise
        // Must save this data BEFORE we reinstall Magento and erase the original app/etc/env.php file
        $httpCacheHost = '';
        /** @var Php $phpContainer */
        $phpContainer = $appContainers->getService(AppContainers::PHP_SERVICE);

        try {
            $env = $this->magento->getEnvPhp($phpContainer); // Exception is thrown here if `env.php` is missing
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
        $magentoVersion = $this->magento->getMagentoVersion($phpContainer);
        $this->applyHotfixes($phpContainer, $magentoVersion, $output);

        $dbName = $mysqlService->getMysqlDatabase();
        $dbUser = $mysqlService->getMysqlUser();
        $dbPassword = escapeshellarg($mysqlService->getMysqlPassword());
        $tablePrefix = $mysqlService->getTablePrefix();

        // @TODO: `--backend-frontname='admin'` must be a parameter. Random name must be used by default
        $escapedAdminPassword = escapeshellarg('q1w2e3r4');
        $installationCommand = <<<BASH
            setup:install \
                --admin-firstname='Magento' --admin-lastname='Administrator' \
                --admin-email='email@example.com' --admin-user='development' --admin-password=$escapedAdminPassword \
                --base-url=$baseUrl  --base-url-secure=$baseUrl \
                --db-name=$dbName --db-user='$dbUser' --db-password=$dbPassword \
                --db-prefix=$tablePrefix --db-host=mysql \
                --use-rewrites=1 --use-secure=1 --use-secure-admin=1 \
                --session-save=files --language=en_US --sales-order-increment-prefix='ORD$' \
                --currency=USD --timezone=America/Chicago --cleanup-database
        BASH;

        if ($appContainers->hasService(AppContainers::ELASTICSEARCH_SERVICE)) {
            if (Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.0')) {
                $installationCommand .= ' --elasticsearch-host=' . AppContainers::ELASTICSEARCH_SERVICE;
            }

            if (Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.8')) {
                $installationCommand .= ' --search-engine=elasticsearch8';
            } elseif (Comparator::greaterThanOrEqualTo($magentoVersion, '2.4.4')) {
                $installationCommand .= ' --search-engine=elasticsearch7';
            }
        }

        if ($appContainers->hasService(AppContainers::OPENSEARCH_SERVICE)) {
            $installationCommand .= ' --search-engine=opensearch';
            $installationCommand .= ' --opensearch-host=' . AppContainers::OPENSEARCH_SERVICE;
        }

        $isSuppressed = $output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET;
        $appContainers->runMagentoCommand(
            $installationCommand,
            $isSuppressed,
            Shell::EXECUTION_TIMEOUT_LONG
        );
        $this->updateMagentoConfig($appContainers, $magentoVersion, $httpCacheHost, $isSuppressed);

        $env = $this->magento->getEnvPhp($phpContainer);
        $this->validateServiceConfiguration($appContainers, $env);
        $output->writeln(<<<EOF
            <info>

            *** Success! ***
            Frontend: <fg=blue>https://$mainDomain/</fg=blue>
            Admin Panel: <fg=blue>https://$mainDomain/{$env['backend']['frontName']}/</fg=blue>
            </info>
            EOF);
    }

    /**
     * Configure Magento settings after installation via native MySQL insert queries
     *
     * @param AppContainers $appContainers
     * @param string $magentoVersion
     * @param string $httpCacheHost
     * @param bool $isQuiet
     * @return void
     * @throws \JsonException
     */
    private function updateMagentoConfig(
        AppContainers $appContainers,
        string $magentoVersion,
        string $httpCacheHost = '',
        bool $isQuiet = false
    ): void {
        $mainDomain = $appContainers->getMainDomain();

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
            // This is PHP server port, not Varnish. Thus, it is always 80 at least in our compositions
            $appContainers->insertConfig('system/full_page_cache/varnish/backend_port', 80);
            $appContainers->insertConfig('system/full_page_cache/varnish/grace_period', 300);
        }

        // Configure Valkey or Redis for cache and session storage.
        // Magento uses the Redis adapter for Valkey — the host is the only difference.
        if ($appContainers->hasService(AppContainers::VALKEY_SERVICE)) {
            $cacheHost = AppContainers::VALKEY_SERVICE;
        } elseif ($appContainers->hasService(AppContainers::REDIS_SERVICE)) {
            $cacheHost = AppContainers::REDIS_SERVICE;
        } else {
            $cacheHost = '';
        }

        if ($cacheHost) {
            $appContainers->runMagentoCommand(
                "setup:config:set --cache-backend=redis --cache-backend-redis-server=$cacheHost"
                    . ' --cache-backend-redis-port=6379 --cache-backend-redis-db=0',
                $isQuiet
            );
            $appContainers->runMagentoCommand(
                "setup:config:set --page-cache=redis --page-cache-redis-server=$cacheHost"
                    . ' --page-cache-redis-port=6379 --page-cache-redis-db=1',
                $isQuiet
            );
            $appContainers->runMagentoCommand(
                "setup:config:set --session-save=redis --session-save-redis-host=$cacheHost"
                    . ' --session-save-redis-port=6379 --session-save-redis-db=2',
                $isQuiet
            );
        }

        if ($appContainers->hasService(AppContainers::ACTIVEMQ_ARTEMIS_SERVICE)) {
            $artemisService = $appContainers->getService(AppContainers::ACTIVEMQ_ARTEMIS_SERVICE);
            $appContainers->runMagentoCommand(
                sprintf(
                    'setup:config:set --stomp-host=activemq-artemis --stomp-port=61613 --stomp-user=%s'
                        . ' --stomp-password=%s',
                    $artemisService->getEnvironmentVariable('ARTEMIS_USER'),
                    $artemisService->getEnvironmentVariable('ARTEMIS_PASSWORD')
                ),
                $isQuiet
            );
        } elseif ($appContainers->hasService(AppContainers::RABBITMQ_SERVICE)) {
            $rabbitmqService = $appContainers->getService(AppContainers::RABBITMQ_SERVICE);
            $appContainers->runMagentoCommand(
                sprintf(
                    'setup:config:set --amqp-host=rabbitmq --amqp-port=5672 --amqp-user=%s --amqp-password=%s'
                        . ' --amqp-virtualhost=/',
                    $rabbitmqService->getEnvironmentVariable('RABBITMQ_DEFAULT_USER'),
                    $rabbitmqService->getEnvironmentVariable('RABBITMQ_DEFAULT_PASS')
                ),
                $isQuiet
            );
        }

        $appContainers->runMagentoCommand('cache:clean', $isQuiet);
        $appContainers->runMagentoCommand('cache:flush', $isQuiet);
    }

    /**
     * Validate that all running services are reflected in app/etc/env.php.
     * Catches silent misconfigurations where a service container is up but Magento isn't actually using it.
     *
     * @param AppContainers $appContainers
     * @param array $env
     * @return void
     */
    private function validateServiceConfiguration(AppContainers $appContainers, array $env): void
    {
        $errors = [];

        // Validate Valkey/Redis cache and session configuration
        $cacheHost = '';

        if ($appContainers->hasService(AppContainers::VALKEY_SERVICE)) {
            $cacheHost = AppContainers::VALKEY_SERVICE;
        } elseif ($appContainers->hasService(AppContainers::REDIS_SERVICE)) {
            $cacheHost = AppContainers::REDIS_SERVICE;
        }

        if ($cacheHost) {
            $defaultCacheServer = $env['cache']['frontend']['default']['backend_options']['server'] ?? '';

            if ($defaultCacheServer !== $cacheHost) {
                $errors[] = "Default cache backend not configured for $cacheHost (got: '$defaultCacheServer')";
            }

            $pageCacheServer = $env['cache']['frontend']['page_cache']['backend_options']['server'] ?? '';

            if ($pageCacheServer !== $cacheHost) {
                $errors[] = "Page cache backend not configured for $cacheHost (got: '$pageCacheServer')";
            }

            $sessionHost = $env['session']['redis']['host'] ?? '';

            if ($sessionHost !== $cacheHost) {
                $errors[] = "Session storage not configured for $cacheHost (got: '$sessionHost')";
            }
        }

        // Validate ActiveMQ Artemis STOMP configuration
        if ($appContainers->hasService(AppContainers::ACTIVEMQ_ARTEMIS_SERVICE)) {
            $stompHost = $env['queue']['stomp']['host'] ?? '';

            if ($stompHost !== AppContainers::ACTIVEMQ_ARTEMIS_SERVICE) {
                $errors[] = "ActiveMQ Artemis STOMP not configured in queue/stomp/host (got: '$stompHost')";
            }
        }

        // Validate RabbitMQ AMQP configuration
        if ($appContainers->hasService(AppContainers::RABBITMQ_SERVICE)) {
            $amqpHost = $env['queue']['amqp']['host'] ?? '';

            if ($amqpHost !== AppContainers::RABBITMQ_SERVICE) {
                $errors[] = "RabbitMQ not configured in queue/amqp/host (got: '$amqpHost')";
            }
        }

        // Validate Varnish HTTP cache configuration
        if ($appContainers->hasService(AppContainers::VARNISH_SERVICE)) {
            $httpCacheHosts = array_column($env['http_cache_hosts'] ?? [], 'host');

            if (!in_array(AppContainers::VARNISH_SERVICE, $httpCacheHosts, true)) {
                $errors[] = sprintf(
                    'Varnish not configured in http_cache_hosts (got: [%s])',
                    implode(', ', $httpCacheHosts)
                );
            }
        }

        if ($errors) {
            throw new \RuntimeException(
                "env.php service configuration validation failed:\n- " . implode("\n- ", $errors)
            );
        }
    }

    /**
     * Apply hotfixes for known Magento issues that prevent setup:install from completing
     *
     * @param Php $phpContainer
     * @param string $magentoVersion
     * @param OutputInterface $output
     * @return void
     */
    private function applyHotfixes(Php $phpContainer, string $magentoVersion, OutputInterface $output): void
    {
        // ACSD-59280: Fix "Call to undefined method ReflectionUnionType::getName()" in Magento code generator.
        // Affects Magento 2.4.4-p1 - 2.4.4-p10. Fixed in 2.4.5+.
        // @see https://experienceleague.adobe.com/en/docs/commerce-operations/tools/quality-patches-tool/patches-available-in-qpt/v1-1-50/acsd-59280-fix-for-reflection-union-type-error
        if (
            Comparator::greaterThan($magentoVersion, '2.4.4')
            && Comparator::lessThan($magentoVersion, '2.4.5')
        ) {
            $output->writeln('Applying ACSD-59280 hotfix for ReflectionUnionType...');
            $phpContainer->mustRun(
                'composer require magento/quality-patches --no-interaction',
                Shell::EXECUTION_TIMEOUT_MEDIUM,
                false
            );
            $phpContainer->mustRun(
                './vendor/bin/magento-patches apply ACSD-59280',
                Shell::EXECUTION_TIMEOUT_SHORT,
                false
            );
            $output->writeln('<info>Applied ACSD-59280 patch successfully</info>');
        }
    }
}
