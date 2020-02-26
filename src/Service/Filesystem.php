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
    /**
     * Traefik rules location to add SSL certificates
     */
    private const FILE_TRAEFIK_RULES = 'docker_infrastructure/local_infrastructure/traefik_rules/rules.toml';

    /**
     * Local Docker-based infrastructure with (at least) Traefik reverse-proxy and MySQL containers
     */
    public const DIR_LOCAL_INFRASTRUCTURE = 'docker_infrastructure/local_infrastructure';

    /**
     * Docker files for the projects. Used as a template
     */
    public const DIR_PROJECT_TEMPLATE = 'docker_infrastructure/templates/project/';

    /**
     * Dockerfiles for various PHP versions
     */
    public const DIR_PHP_DOCKERFILES = 'docker_infrastructure/templates/php/';

    /**
     * @var array $authJson
     */
    private static $authJson;

    /**
     * @var string $dockerizerRootDir
     */
    private $dockerizerRootDir;

    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * Filesystem constructor.
     * Automatically validate `auth.json` location and content.
     * @param \App\Config\Env $env
     */
    public function __construct(\App\Config\Env $env)
    {
        $this->dockerizerRootDir = dirname(__DIR__, 2);
        $this->env = $env;
        $this->validateEnv();
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
                throw new FilesystemException("Can\'t copy auth.json to '$destination'");
            }
        }
    }

    /**
     * @return string
     */
    private function getAuthJsonLocation(): string
    {
        $authJsonLocation = $this->dockerizerRootDir
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auth.json';

        if (!file_exists($authJsonLocation)) {
            throw new FilesystemException(
                "Magento auth.json does not exist in $authJsonLocation! "
                . 'Ensure that file exists and contains your Magento marketplace credentials.'
            );
        }

        return $authJsonLocation;
    }

    /**
     * @return array
     */
    public function getProjectTemplateFiles(): array
    {
        $projectTemplateDir = $this->getDir(self::DIR_PROJECT_TEMPLATE);
        $files = array_merge(
            array_filter(
                glob($projectTemplateDir . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE),
                'is_file'
            ),
            array_filter(
                glob($projectTemplateDir . 'docker' . DIRECTORY_SEPARATOR . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE),
                'is_file'
            )
        );

        $files = array_filter($files, static function ($file) {
            return strpos($file, '.DS_Store') === false && strpos($file, 'docker-compose-dev.yml') === false;
        });

        array_walk($files, static function (&$value) use ($projectTemplateDir) {
            $value = str_replace($projectTemplateDir, '', $value);
        });

        return $files;
    }

    /**
     * @return string
     */
    public function getTraefikRulesFile(): string
    {
        return $this->env->getProjectsRootDir() . str_replace('/', DIRECTORY_SEPARATOR, self::FILE_TRAEFIK_RULES);
    }

    /**
     * @return array
     */
    public function getAvailablePhpVersions(): array
    {
        $availablePhpVersions = array_filter(glob(
            $this->getDir(self::DIR_PHP_DOCKERFILES) . '*'
        ), 'is_dir');

        array_walk($availablePhpVersions, static function (&$value) {
            $value = number_format((float) basename($value), 1);
        });

        return $availablePhpVersions;
    }

    /**
     * Get path to the directory, create it if needed
     *
     * @param string $dir
     * @param bool $create
     * @return string
     */
    public function getDir(string $dir, bool $create = false): string
    {
        $dir = $this->env->getProjectsRootDir()
            . str_replace('/', DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR))
            . DIRECTORY_SEPARATOR;

        if ($create && !@mkdir($dir) && !is_dir($dir)) {
            throw new FilesystemException(sprintf('Directory "%s" was not created', $create));
        }

        if (!is_dir($dir) || !$this->isWritable($dir)) {
            throw new FilesystemException("Directory $dir does not exist or is not writeable");
        }

        return $dir;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isWritable(string $path): bool
    {
        return (is_dir($path) || is_file($path)) && is_writable($path);
    }

    /**
     * Validate environment integrity for successful commands execution
     */
    private function validateEnv(): void
    {
        $this->getAuthJsonContent();
        $this->isWritable($this->env->getProjectsRootDir());
        $this->isWritable($this->env->getSslCertificatesDir());
        $this->isWritable($this->getTraefikRulesFile());
        $this->getDir(self::DIR_PHP_DOCKERFILES);
        $this->getDir(self::DIR_PROJECT_TEMPLATE);

        if (!$this->isWritable($this->getTraefikRulesFile())) {
            throw new FilesystemException(
                "Missing Traefik SSL configuration file: {$this->getTraefikRulesFile()}\n"
                . 'Maybe infrastructure has not been set up yet'
            );
        }
    }
}
