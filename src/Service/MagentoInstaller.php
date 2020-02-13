<?php

declare(strict_types=1);

namespace App\Service;

class MagentoInstaller
{
    public const TABLE_PREFIX = 'm2_';
    /**
     * @var Database
     */
    private $database;

    /**
     * MagentoInstaller constructor.
     * @param Database $database
     */
    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Drop database if exists, create database and user, install Magento
     *
     * @param string $domain
     */
    public function refreshDbAndInstall(string $domain): void
    {
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
        BASH);
    }

    /**
     * Sing static files, move JS to bottom and set proper config for web root in 'pub/' folder
     */
    public function updateMagentoConfig(string $mainDomain): void
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
