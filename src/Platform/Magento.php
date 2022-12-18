<?php

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
    public const MAGENTO_CE_PACKAGE = 'magento/project-community-edition';

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysqlService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch $elasticsearchService
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpService,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysqlService,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch $elasticsearchService
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
            $tablePrefix = $this->getEnv($projectRoot)['db']['table_prefix'];
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
        }

        // @TODO: add services like Redis, RabbitMQ, Varnish, etc.
        return new AppContainers($containerizedServices);
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
    public function getEnv(string $projectRoot): array
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
}
