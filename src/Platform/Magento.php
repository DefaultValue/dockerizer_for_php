<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform;

use DefaultValue\Dockerizer\Console\Shell\Shell;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\AbstractService;
use Symfony\Component\Process\Process;

/**
 * @TODO: test and add support for EE, B2B, Cloud
 * @TODO: Move initialization and some functionality to AbstractPlatform. It will be easier to work with Docker services
 */
class Magento
{
    public const PHP_SERVICE = 'php';
    public const MYSQL_SERVICE = 'mysql';
    public const ELASTICSEARCH_SERVICE = 'elasticsearch';
    public const VARNISH_SERVICE = 'varnish-cache';

    public const MAGENTO_CE_PACKAGE = 'magento/project-community-edition';

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param AbstractService[] $containerizedServices
     * @param string $projectRoot
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php|null $phpService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL|null $mysqlService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch|null $elasticsearchService
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private array $containerizedServices = [],
        private string $projectRoot = '',
        private ?\DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpService = null,
        private ?\DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL $mysqlService = null,
        private ?\DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch $elasticsearchService = null
    ) {
    }

    /**
     * @param Compose $dockerCompose
     * @param string $projectRoot
     * @return $this
     * @throws \Exception
     */
    public function initialize(Compose $dockerCompose, string $projectRoot): static
    {
        $containerizedServices = [
            $this->phpService->initialize($dockerCompose->getServiceContainerName(self::PHP_SERVICE)),
            $this->mysqlService->initialize($dockerCompose->getServiceContainerName(self::MYSQL_SERVICE))
        ];

        if ($dockerCompose->hasService(self::ELASTICSEARCH_SERVICE)) {
            $containerizedServices[] = $this->elasticsearchService->initialize(
                $dockerCompose->getServiceContainerName(self::ELASTICSEARCH_SERVICE)
            );
        }

        // @TODO: add services like Redis, RabbitMQ, Varnish, etc.
        return new static($this->filesystem, $this->docker, $containerizedServices);
    }

    /**
     * @param string $serviceName
     * @return AbstractService
     */
    public function getService(string $serviceName): AbstractService
    {
        return $this->containerizedServices[$serviceName]
            ?? throw new \InvalidArgumentException("Service $serviceName is not available in this composition!");
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    public function hasService(string $serviceName): bool
    {
        return isset($this->containerizedServices[$serviceName]);
    }

    /**
     * @return void
     */
    public function validateIsMagento(): void
    {
        // Can't check `app/etc/.env.php` because it may not yet exist
        // Can't check `composer.json` because cloned Magento instances may have a very customized file
        if (
            !$this->filesystem->isFile('bin/magento')
            || !$this->filesystem->isFile('app/etc/di.xml')
            || !$this->filesystem->isFile('app/etc/NonComposerComponentRegistration.php')
            || !$this->filesystem->isFile('setup/src/Magento/Setup/Console/Command/InstallCommand.php')
        ) {
            throw new \RuntimeException('Current directory is not a Magento project root!');
        }
    }

    public function getMagentoVersion(): string
    {
        $process = $this->runMagentoCommand('--version', false);
        // Magento CLI 2.3.1
        $output = $process->getOutput();

$foo = false;

    }

    /**
     * Get Magento `app/etc/env.php` data
     *
     * @return array
     */
    public function getEnv(): array
    {
        return include $this->projectRoot . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);
    }


    /**
     * @param string $command
     * @param bool $isQuite
     * @param float|null $timeout
     * @return Process
     */
    public function runMagentoCommand(
        string $command,
        bool $isQuite,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT
    ): Process {
        $fullCommand = 'php bin/magento ';
        $fullCommand .= $isQuite ? '-q ' : '';
        $fullCommand .= $command;

        return $this->getService(self::PHP_SERVICE)->mustRun($fullCommand, $timeout);
    }
}
