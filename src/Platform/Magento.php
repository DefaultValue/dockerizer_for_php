<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform;

use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\ContainerizedService\AbstractService;
use DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL;
use DefaultValue\Dockerizer\Platform\Magento\Exception\MagentoNotInstalledException;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

/**
 * @TODO: test and add support for EE, B2B, Cloud
 * @TODO: Move initialization and some functionality to AbstractPlatform. It will be easier to work with Docker services
 */
class Magento
{
    public const MAGENTO_CE_PACKAGE = 'magento/project-community-edition';

    public const PHP_SERVICE = 'php';
    public const MYSQL_SERVICE = 'mysql';
    public const ELASTICSEARCH_SERVICE = 'elasticsearch';
    public const VARNISH_SERVICE = 'varnish-cache';

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param AbstractService[] $containerizedServices
     * @param string $projectRoot
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php|null $phpService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\MySQL|null $mysqlService
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Elasticsearch|null $elasticsearchService
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
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
            self::PHP_SERVICE =>  $this->phpService->initialize(
                $dockerCompose->getServiceContainerName(self::PHP_SERVICE)
            ),
            self::MYSQL_SERVICE => $this->mysqlService->initialize(
                $dockerCompose->getServiceContainerName(self::MYSQL_SERVICE)
            )
        ];

        if ($dockerCompose->hasService(self::ELASTICSEARCH_SERVICE)) {
            $containerizedServices[self::ELASTICSEARCH_SERVICE] = $this->elasticsearchService->initialize(
                $dockerCompose->getServiceContainerName(self::ELASTICSEARCH_SERVICE)
            );
        }

        // @TODO: add services like Redis, RabbitMQ, Varnish, etc.
        return new static($this->filesystem, $this->docker, $this->shell, $containerizedServices, $projectRoot);
    }

    /**
     * @param string $serviceName
     * @return AbstractService
     */
    public function getService(string $serviceName): AbstractService
    {
        $service = $this->containerizedServices[$serviceName]
            ?? throw new \InvalidArgumentException("Service $serviceName is not available in this composition!");

        // Automatically use Magento DB if available
        if (
            $service instanceof MySQL
            && ($env = $this->getEnv(false))
        ) {
            $service->useDatabase($env);
        }

        return $service;
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

    /**
     * @return string
     */
    public function getMagentoVersion(): string
    {
        $process = $this->runMagentoCommand('--version', false, Shell::EXECUTION_TIMEOUT_SHORT, false);
        // Magento CLI 2.3.1
        $output = trim($process->getOutput());
        return substr($output, strpos($output, '2.'));
    }

    /**
     * Get main domain from the database, or get it from the PHP container labels otherwise.
     * Domain is not yet available in the database while installing Magento.
     *
     * @return string
     */
    public function getMainDomain(): string
    {
        $baseUrl = $this->getConfig('web/unsecure/base_url');

        return parse_url($baseUrl)['host'];

        // Can't get from labels, because PHP container may not have labels in case of Nginx > Varnish > PHP schema
        /*
        $phpContainerName = $this->getService(self::PHP_SERVICE)->getContainerName();
        $process = $this->shell->mustRun(
            "docker inspect -f '{{if .State.Running}} {{json .Config.Labels}} {{end}}' $phpContainerName"
        );
        $labels = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        // traefik.http.routers.example-local-https.rule=Host(`example.local`,`www.example.local`)'
        $labels = array_filter($labels, static function (string $value, string $key) {
            return str_starts_with($key, 'traefik.http.routers.') && str_ends_with($key, '-http.rule');
        }, ARRAY_FILTER_USE_BOTH);

        if (count($labels) !== 1) {
            throw new \RuntimeException(
                'PHP container expects to have one HTTP Traefik label starting with ' .
                '`traefik.http.routers.` and ending with `-http.rule`'
            );
        }

        return explode('`', array_values($labels)[0])[1];
        */
    }

    /**
     * Get Magento `app/etc/env.php` data
     *
     * @param bool $throwOnError
     * @return array
     */
    public function getEnv(bool $throwOnError = true): array
    {
        $envFile = $this->projectRoot . implode(DIRECTORY_SEPARATOR, ['app', 'etc', 'env.php']);

        if (!$this->filesystem->isFile($envFile, true)) {
            if ($throwOnError) {
                throw new MagentoNotInstalledException(
                    'The file ./app/etc/env.php does not exist. Magento may not be installed!'
                );
            }

            return [];
        }

        return include $envFile;
    }

    /**
     * @param string $command
     * @param bool $isQuite
     * @param float|null $timeout
     * @param bool $tty
     * @return Process
     */
    public function runMagentoCommand(
        string $command,
        bool $isQuite,
        ?float $timeout = Shell::EXECUTION_TIMEOUT_SHORT,
        bool $tty = true
    ): Process {
        $fullCommand = 'php bin/magento ';
        $fullCommand .= $isQuite ? '-q ' : '';
        $fullCommand .= $command;

        return $this->getService(self::PHP_SERVICE)->mustRun($fullCommand, $timeout, $tty);
    }

    /**
     * Save config to the default scope
     *
     * @param string $path
     * @param string|int $value
     * @return void
     */
    public function insertConfig(string $path, string|int $value): void
    {
        /** @var MySQL $mysqlService */
        $mysqlService = $this->getService(self::MYSQL_SERVICE);
        $mysqlService->prepareAndExecute(
            sprintf(
                "INSERT INTO `%s` (`scope`, `scope_id`, `path`, `value`) VALUES ('default', 0, :path, :value)",
                $mysqlService->getTableName('core_config_data')
            ),
            [
                ':path'  => $path,
                ':value' => $value
            ]
        );
    }

    /**
     * Get config fromt he default scope
     *
     * @param string $path
     * @return string
     */
    public function getConfig(string $path): string
    {
        /** @var MySQL $mysqlService */
        $mysqlService = $this->getService(self::MYSQL_SERVICE);
        $statement = $mysqlService->prepareAndExecute(
            sprintf(
                'SELECT `value` FROM `%s` WHERE `scope`="default" AND scope_id=0 AND `path`=:path',
                $mysqlService->getTableName('core_config_data')
            ),
            [
                ':path'  => $path,
            ]
        );

        $result = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (count($result) !== 1) {
            throw new \RuntimeException("No Magento config set for $path");
        }

        return $result[0]['value'];
    }
}
