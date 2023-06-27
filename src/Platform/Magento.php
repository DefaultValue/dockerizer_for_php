<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform;

use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Platform\Magento\AppContainers;
use DefaultValue\Dockerizer\Platform\Magento\Exception\MagentoNotInstalledException;

/**
 * @TODO: add support for EE, B2B, Cloud
 * @TODO: Move initialization and some functionality to AbstractPlatform. It will be easier to work with Docker services
 */
class Magento
{
    // For `composer craete-project` only
    public const MAGENTO_CE_PROJECT = 'magento/project-community-edition';
    public const MAGENTO_CE_PRODUCT = 'magento/product-community-edition';

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysqlService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch $elasticsearchService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Opensearch $opensearchService
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpService,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysqlService,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch $elasticsearchService,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Opensearch $opensearchService,
    ) {
    }

    /**
     * @param Compose $dockerCompose
     * @param string $projectRoot
     * @return AppContainers
     * @throws \Exception
     */
    public function initialize(Compose $dockerCompose, string $projectRoot): AppContainers
    {
        $this->validateIsMagento($projectRoot);

        // @TODO move table prefix to parameters!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        // At least we've moved this out of the MySQL class because this is a Magento-specific thing
        try {
            $tablePrefix = $this->getEnvPhp($projectRoot)['db']['table_prefix'];
        } catch (MagentoNotInstalledException) {
            $tablePrefix = 'm2_';
        }

        $containerizedServices = [
            AppContainers::PHP_SERVICE =>  $this->phpService->initialize(
                $dockerCompose->getServiceContainerName(AppContainers::PHP_SERVICE)
            ),
            AppContainers::MYSQL_SERVICE => $this->mysqlService->initialize(
                $dockerCompose->getServiceContainerName(AppContainers::MYSQL_SERVICE),
                $tablePrefix
            )
        ];

        if ($dockerCompose->hasService(AppContainers::ELASTICSEARCH_SERVICE)) {
            $containerizedServices[AppContainers::ELASTICSEARCH_SERVICE] = $this->elasticsearchService->initialize(
                $dockerCompose->getServiceContainerName(AppContainers::ELASTICSEARCH_SERVICE)
            );
        } elseif ($dockerCompose->hasService(AppContainers::OPENSEARCH_SERVICE)) {
            $containerizedServices[AppContainers::OPENSEARCH_SERVICE] = $this->opensearchService->initialize(
                $dockerCompose->getServiceContainerName(AppContainers::OPENSEARCH_SERVICE)
            );
        }

        // @TODO: add services like Redis, RabbitMQ, Varnish, etc.
        return new AppContainers($containerizedServices);
    }

    /**
     * @param string $projectRoot
     * @return void
     * @throws \RuntimeException
     */
    public function validateIsMagento(string $projectRoot): void
    {
        // Can't check `app/etc/env.php` because it may not yet exist
        // Can't check `composer.json` because cloned Magento instances may have a very customized file
        if (
            !$this->filesystem->isFile($projectRoot . 'bin/magento')
            || !$this->filesystem->isFile($projectRoot . 'app/etc/di.xml')
            || !$this->filesystem->isFile($projectRoot . 'app/etc/NonComposerComponentRegistration.php')
            || !$this->filesystem->isFile($projectRoot . 'setup/src/Magento/Setup/Console/Command/InstallCommand.php')
        ) {
            throw new \RuntimeException('Current directory is not a Magento project root!');
        }
    }

    /**
     * Get Magento `app/etc/env.php` data
     *
     * @param string $projectRoot
     * @return array{
     *     'db': array{ 'table_prefix': string },
     *     'backend': array{ 'frontName': string },
     *     'http_cache_hosts'?: array{0: array{'host': string, 'port': int}}
     * }
     */
    public function getEnvPhp(string $projectRoot): array
    {
        $envFile = $projectRoot . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);

        if (!$this->filesystem->isFile($envFile, true)) {
            throw new MagentoNotInstalledException(
                'The file ./app/etc/env.php does not exist. Magento may not be installed!'
            );
        }

        return include $envFile;
    }

    /**
     * This data may be wrong if `composer.lock` is missed and `composer.json` is updated in the wrong way
     * We don't use `php bin/magento --version` because it often fails with the following error:
     * > PHP Warning:  require(/var/www/html/vendor/composer/../symfony/polyfill-ctype/bootstrap.php): failed to open stream: No such file or directory in /var/www/html/vendor/composer/autoload_real.php on line 74
     * > PHP Fatal error:  require(): Failed opening required '/var/www/html/vendor/composer/../symfony/polyfill-ctype/bootstrap.php' (include_path='/var/www/html/vendor/magento/zendframework1/library:/var/www/html/vendor/phpunit/php-file-itera
     * > tor:/var/www/html/vendor/phpunit/phpunit:/var/www/html/vendor/symfony/yaml:.:/usr/local/lib/php') in /var/www/html/vendor/composer/autoload_real.php on line 74
     *
     * @return string
     */
    public function getMagentoVersion(string $projectRoot): string
    {
        // Try reading `composer.lock` to get Magento version
        $composerLockFile = $projectRoot . 'composer.lock';

        if ($this->filesystem->isFile($composerLockFile)) {
            $composerLockContent = $this->filesystem->fileGetContents($composerLockFile);
            $composerLock = json_decode($composerLockContent, true, 512, JSON_THROW_ON_ERROR);

            foreach ($composerLock['packages'] as $package) {
                if ($package['name'] === self::MAGENTO_CE_PRODUCT) {
                    return $package['version'];
                }
            }
        }

        // Otherwise, try reading `composer.json` to get Magento version
        $composerJsonFile = $projectRoot . 'composer.json';
        $composerJsonContent = $this->filesystem->fileGetContents($composerJsonFile);
        $composerJson = json_decode($composerJsonContent, true, 512, JSON_THROW_ON_ERROR);

        foreach ($composerJson['require'] as $packageName => $version) {
            if ($packageName === self::MAGENTO_CE_PRODUCT) {
                return $version;
            }
        }

        throw new \RuntimeException('Unable to determine Magento version via "composer.lock" or "composer.json"!');
    }
}
