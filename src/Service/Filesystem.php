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
    private const FILE_TRAEFIK_RULES = 'docker_infrastructure/local_infrastructure/configuration/certificates.toml';

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
     * Array keys array containing SSL certificate file names
     */
    public const SSL_CERTIFICATE_FILE = 'ssl_certificate_file';
    public const SSL_CERTIFICATE_KEY_FILE = 'ssl_certificate_key_file';

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
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * Filesystem constructor.
     * Automatically validate `auth.json` location and content.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell
    ) {
        $this->dockerizerRootDir = dirname(__DIR__, 2);
        $this->env = $env;
        $this->validateEnv();
        $this->shell = $shell;
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
        $authJsonLocation = $this->dockerizerRootDir .
            DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auth.json';

        if (!file_exists($authJsonLocation)) {
            throw new FilesystemException(
                "Magento auth.json does not exist in $authJsonLocation! " .
                'Ensure that file exists and contains your Magento marketplace credentials.'
            );
        }

        return $authJsonLocation;
    }

    /**
     * @return array
     */
    public function getProjectTemplateFiles(): array
    {
        $projectTemplateDir = $this->getDirPath(self::DIR_PROJECT_TEMPLATE);
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
            $this->getDirPath(self::DIR_PHP_DOCKERFILES) . '*'
        ), 'is_dir');

        array_walk($availablePhpVersions, static function (&$value) {
            $value = number_format((float) basename($value), 1);
        });

        $availablePhpVersions = array_filter($availablePhpVersions, static function ($version) {
            return $version > 5 && $version < 8;
        });

        return $availablePhpVersions;
    }

    /**
     * @param array $domains
     * @return array
     */
    public function generateSslCertificates(array $domains): array
    {
        $sslCertificateDir = $this->env->getSslCertificatesDir();
        $additionalDomainsCount = count($domains) - 1;
        $sslCertificateFile = sprintf(
            '%s%s.pem',
            $domains[0],
            $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
        );
        $sslCertificateKeyFile = sprintf(
            '%s%s-key.pem',
            $domains[0],
            $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
        );
        $domainsString = implode(' ', $domains);
        $this->shell->passthru("mkcert $domainsString 2>/dev/null", false, $sslCertificateDir);

        // @TODO: resolve the conflict when certificates exist - generate new certs with some hash suffix
        $result = [
            self::SSL_CERTIFICATE_FILE => $sslCertificateFile,
            self::SSL_CERTIFICATE_KEY_FILE => $sslCertificateKeyFile,
        ];

        if (
            !$this->isWritableFile($sslCertificateDir . $sslCertificateFile)
            || !$this->isWritableFile($sslCertificateDir . $sslCertificateKeyFile)
        ) {
            throw new \RuntimeException('Unable to generate SSL certificates for the project');
        }

        return $result;
    }

    /**
     * Get path to the directory, create it if needed
     *
     * @param string $dir
     * @param bool $create
     * @return string
     */
    public function getDirPath(string $dir, bool $create = false): string
    {
        $dirPath = $this->env->getProjectsRootDir() .
            str_replace('/', DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR)) .
            DIRECTORY_SEPARATOR;

        if ($create && !@mkdir($dirPath) && !is_dir($dirPath)) {
            throw new FilesystemException("Can't create directory: $dirPath");
        }

        if (!$this->isWritableDir($dirPath)) {
            throw new FilesystemException("Directory doesn't exist or isn't writeable: $dirPath");
        }

        return $dirPath;
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isWritableFile(string $path): bool
    {
        return is_file($path) && is_writable($path);
    }

    /**
     * @param string $dirPath
     * @return bool
     */
    public function isEmptyDir(string $dirPath): bool
    {
        $handle = opendir($dirPath);

        while (false !== ($entry = readdir($handle))) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isWritableDir(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    /**
     * Validate environment integrity for successful commands execution
     */
    private function validateEnv(): void
    {
        $this->getAuthJsonContent();

        if (!$this->isWritableDir($this->env->getProjectsRootDir())) {
            throw new FilesystemException(
                "Directory does not exist or is not writeable: {$this->env->getProjectsRootDir()}"
            );
        }

        if (!$this->isWritableDir($this->env->getSslCertificatesDir())) {
            throw new FilesystemException(
                "Directory does not exist or is not writeable: {$this->env->getSslCertificatesDir()}"
            );
        }

        $this->getDirPath(self::DIR_PHP_DOCKERFILES);
        $this->getDirPath(self::DIR_PROJECT_TEMPLATE);

        if (!$this->isWritableFile($this->getTraefikRulesFile())) {
            throw new FilesystemException(
                "Missing Traefik SSL configuration file: {$this->getTraefikRulesFile()}\n" .
                'Maybe the infrastructure has not been set up yet.'
            );
        }
    }
}
