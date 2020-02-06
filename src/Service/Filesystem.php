<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Hardcoded class to work with Docker files, auth.json and other filesystem objects.
 * Need to as least isolate all this information and actions in one place instead of duplicating them among commands
 * or other services.
 */
class Filesystem
{
    private static $authJson = [];

    /**
     * Filesystem constructor.
     * Automatically validate `auth.json` location and content.
     */
    public function __construct()
    {
        $this->getAuthJsonContent();
    }

    /**
     * @return array
     */
    public function getAuthJsonContent(): array
    {
        if (!isset(self::$authJson)) {
            $authJson = json_decode(
                file_get_contents($this->getAuthJsonLocation()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !isset(
                    $authJson['http-basic']['repo.magento.com']['username'],
                    $authJson['http-basic']['repo.magento.com']['password']
                )
            ) {
                throw new \RuntimeException(
                    'The file "auth.json" does not contain "username" or or "password" for "repo.magento.com"'
                );
            }

            self::$authJson = $authJson;
        }

        return self::$authJson;
    }

    /**
     * @param string $destinationDir
     */
    public function copyAuthJson(string $destinationDir = './'): void
    {
        $destination = rtrim($destinationDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'auth.json';

        if (!file_exists($destination)) {
            $authJson = $this->getAuthJsonLocation();

            if (!copy($authJson, $destination) ||  !file_exists($destination)) {
                throw new \RuntimeException("Can\'t copy auth.json to '$destination'");
            }
        }
    }

    /**
     * @return string
     */
    private function getAuthJsonLocation(): string
    {
        $authJsonLocation = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auth.json';

        if (!file_exists($authJsonLocation)) {
            throw new \RuntimeException(
                "Magento auth.json does not exist in $authJsonLocation! "
                . 'Ensure that file exists and contains your Magento marketplace credentials.'
            );
        }

        return $authJsonLocation;
    }
}
