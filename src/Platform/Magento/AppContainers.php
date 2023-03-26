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

use DefaultValue\Dockerizer\Docker\ContainerizedService\AbstractService;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Process\Process;

class AppContainers
{
    public const PHP_SERVICE = 'php';
    public const MYSQL_SERVICE = 'mysql';
    public const ELASTICSEARCH_SERVICE = 'elasticsearch';
    public const VARNISH_SERVICE = 'varnish-cache';

    /**
     * @param AbstractService[] $containerizedServices
     */
    public function __construct(
        private array $containerizedServices = []
    ) {
        if (!$containerizedServices) {
            throw new \LogicException('Not a service. Initialize this class via ' . Magento::class);
        }
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
     * Get main domain from the database, or get it from the PHP container labels otherwise.
     * Domain is not yet available in the database while installing Magento.
     *
     * @return string
     */
    public function getMainDomain(): string
    {
        $baseUrl = $this->getConfig('web/unsecure/base_url');

        return parse_url($baseUrl)['host'] ?? throw new \RuntimeException('Can\'t get Magento Base URL');
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
        // @TODO: `-q` hides all output, including error. At the same time, without `-q` we get output from all threads
        // directly to the console, which is not acceptable.
        $fullCommand .= $isQuite ? '-q ' : '';
        $fullCommand .= $command;

        return $this->getService(self::PHP_SERVICE)->mustRun($fullCommand, $timeout, $tty);
    }

    /**
     * Get config fromt he default scope
     *
     * @param string $path
     * @return string
     */
    public function getConfig(string $path): string
    {
        /** @var Mysql $mysqlService */
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

    /**
     * Save config to the default scope
     *
     * @param string $path
     * @param string|int $value
     * @return void
     */
    public function insertConfig(string $path, string|int $value): void
    {
        /** @var Mysql $mysqlService */
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
}
