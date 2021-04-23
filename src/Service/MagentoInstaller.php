<?php

declare(strict_types=1);

namespace App\Service;

class MagentoInstaller
{
    public const TABLE_PREFIX = 'm2_';

    /**
     * @var \App\Service\Database $database
     */
    private $database;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * MagentoInstaller constructor.
     * @param Database $database
     * @param Shell $shell
     */
    public function __construct(
        \App\Service\Database $database,
        \App\Service\Shell $shell
    ) {
        $this->database = $database;
        $this->shell = $shell;
    }

    /**
     * Drop database if exists, create database and user, install Magento
     *
     * @param string $domain
     * @param bool $useMysqlNativePassword
     * @param ?string $elasticsearchHost
     */
    public function refreshDbAndInstall(
        string $domain,
        bool $useMysqlNativePassword = false,
        ?string $elasticsearchHost = null
    ): void {
        $this->database->refreshDatabase($domain, $useMysqlNativePassword);

        $baseUrl = "https://$domain/";
        $databaseName = $this->database->getDatabaseName($domain);
        $databaseUser = $this->database->getDatabaseUsername($domain);
        $tablePrefix = self::TABLE_PREFIX;
        $installationCommand = <<<BASH
            php bin/magento setup:install \
                --admin-firstname="Magento" --admin-lastname="Administrator" \
                --admin-email="email@example.com" --admin-user="development" --admin-password="q1w2e3r4" \
                --base-url="$baseUrl"  --base-url-secure="$baseUrl" \
                --db-name="$databaseName" --db-user="$databaseUser" --db-password="$databaseName" \
                --db-prefix="$tablePrefix" --db-host="mysql" \
                --use-rewrites=1 --use-secure="1" --use-secure-admin="1" \
                --session-save="files" --language=en_US --sales-order-increment-prefix="ORD$" \
                --currency=USD --timezone=America/Chicago --cleanup-database \
                --backend-frontname="admin"
        BASH;

        if ($elasticsearchHost) {
            $installationCommand .= " --elasticsearch-host=$elasticsearchHost";
        }

        $this->shell->dockerExec($installationCommand, $domain);
    }

    /**
     * Sing static files, move JS to bottom and set proper config for web root in 'pub/' folder
     * @param string $domain
     */
    public function updateMagentoConfig(string $domain): void
    {
        $table = self::TABLE_PREFIX . 'core_config_data';

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
