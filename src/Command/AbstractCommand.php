<?php
declare(strict_types=1);

namespace App\Command;

abstract class AbstractCommand extends \Symfony\Component\Console\Command\Command
{
    public const TABLE_PREFIX = 'm2_';

    public const OPTION_FORCE = 'force';

    /**
     * @var \App\Config\Env $env
     */
    protected $env;

    /**
     * @var \App\Service\Database
     */
    protected $database;

    /**
     * @var \App\Service\DomainValidator
     */
    protected $domainValidator;

    /**
     * @var string $domain
     */
    private $domain = '';

    /**
     * @var string $projectRoot
     */
    private $projectRoot = '';

    /**
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Database $database
     * @param \App\Service\DomainValidator $domainValidator
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Database $database,
        \App\Service\DomainValidator $domainValidator,
        $name = null
    ) {
        parent::__construct($name);

        $this->env = $env;
        $this->database = $database;
        $this->domainValidator = $domainValidator;
    }

    /**
     * @return string
     */
    protected function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @param string $domain
     */
    protected function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    /**
     * @return string
     */
    protected function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @param string $projectRoot
     */
    protected function setProjectRoot(string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Drop database if exists, create database and user, install Magento
     */
    protected function refreshDbAndInstall(): void
    {
        $domain = $this->getDomain();

        $this->database->refreshDatabase($domain);

        $baseUrl = "https://$domain/";
        $databaseName = $this->database->getDatabaseName($domain);
        $databaseUser = $this->database->getDatabaseUsername($domain);
        $tablePrefix = self::TABLE_PREFIX;

        $this->dockerExec(<<<BASH
            php bin/magento setup:install
                --admin-firstname="Maksym" --admin-lastname="Zaporozhets"
                --admin-email="makimz@default-value.com" --admin-user="development" --admin-password="q1w2e3r4"
                --base-url="$baseUrl"  --base-url-secure="$baseUrl"
                --db-name="$databaseName" --db-user="$databaseUser" --db-password="$databaseName" --db-prefix="$tablePrefix"
                --db-host="mysql"
                --use-rewrites=1 --use-secure="1" --use-secure-admin="1"
                --session-save="files" --language=en_US --sales-order-increment-prefix="ORD$"
                --currency=USD --timezone=America/Chicago --cleanup-database
                --backend-frontname="admin"
BASH
        );
    }

    /**
     * @param string $destination
     */
    protected function copyAuthJson(string $destination = './'): void
    {
        if (!file_exists($destination . '/auth.json')) {
            $authJson = $this->env->getAuthJsonLocation();
            copy($authJson, $destination . '/auth.json');
        }
    }

    /**
     * @param string $command
     * @param bool $ignoreErrors
     * @return $this
     */
    protected function passthru(string $command, bool $ignoreErrors = false): self
    {
        $exitCode = 0;

        passthru($command, $exitCode);

        if ($exitCode && !$ignoreErrors) {
            throw new \RuntimeException('Execution failed. External command returned non-zero exit code.');
        }

        return $this;
    }

    /**
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command
     * @param bool $ignoreErrors
     */
    protected function sudoPassthru(string $command, bool $ignoreErrors = false): void
    {

        $this->passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command", $ignoreErrors);
    }

    /**
     * @param string $command
     * @return $this
     * @throws \InvalidArgumentException|\RuntimeException
     */
    protected function dockerExec(string $command): self
    {
        if (!$this->getDomain()) {
            throw new \InvalidArgumentException('Domain is not set. It must be set and equal to the container name.');
        }

        if (!shell_exec("docker ps | grep {$this->getDomain()} | grep 'Up '")) {
            throw new \RuntimeException("Can't continue because the container {$this->getDomain()} is not up and running.");
        }

        $command = "docker exec -it {$this->getDomain()} " . str_replace(["\r", "\n"], '', $command);

        echo "$command\n\n";
        $this->passthru($command);

        return $this;
    }

    /**
     * Sing static files, move JS to bottom and set proper config for web root in 'pub/' folder
     */
    protected function updateMagentoConfig(): void
    {
        $table = self::TABLE_PREFIX . 'core_config_data';
        $domain = $this->getDomain();

        $this->database->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/unsecure/base_static_url',
                'value'    => "https://$domain/static/"
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/unsecure/base_media_url',
                'value'    => "https://$domain/media/"
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/secure/base_static_url',
                'value'    => "https://$domain/static/"
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'web/secure/base_media_url',
                'value'    => "https://$domain/media/"
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'dev/js/move_script_to_bottom',
                'value'    => 1
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'dev/static/sign',
                'value'    => 1
            ]
        )->insertKeyValue(
            $domain,
            $table,
            [
                'scope'    => 'default',
                'scope_id' => 0,
                'path'     => 'dev/css/use_css_critical_path',
                'value'    => 1
            ]
        );
    }
}
