<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Process configurations in different files - replace domains, add some code etc.
 * Ideally, all file modification operations must be moved here or to some file processors pool.
 */
class FileProcessor
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * @var \App\Service\DomainValidator $domainValidator
     */
    private $domainValidator;

    /**
     * FileProcessor constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Filesystem $filesystem
     * @param Shell $shell
     * @param DomainValidator $domainValidator
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Filesystem $filesystem,
        \App\Service\Shell $shell,
        \App\Service\DomainValidator $domainValidator
    ) {
        $this->env = $env;
        $this->filesystem = $filesystem;
        $this->shell = $shell;
        $this->domainValidator = $domainValidator;
    }

    /**
     * @param array $files
     * @param array $search
     * @param array $domains
     * @param string $applicationContainerName
     * @param string $mysqlContainer
     * @return void
     */
    public function processDockerComposeFiles(
        array $files,
        array $search,
        array $domains,
        string $applicationContainerName,
        string $mysqlContainer = ''
    ): void {
        $files = array_filter($files, static function ($file) {
            return preg_match('/docker-.*\.yml/', $file);
        });

        foreach ($files as $file) {
            $newContent = '';

            $fileHandle = fopen($file, 'rb');

            while ($line = fgets($fileHandle)) {
                $line = str_replace(
                    $search,
                    [
                        implode(',', $domains),
                        implode(' ', $domains),
                        $applicationContainerName,
                    ],
                    $line
                );

                if (strpos($line, 'mysql57:mysql') !== false) {
                    if (!$mysqlContainer) {
                        throw new \RuntimeException('MySQL container must be passed to process configuration files');
                    }

                    $line = str_replace('mysql57', $mysqlContainer, $line);
                }

                if (strpos($line, '/misc/share/ssl') !== false) {
                    $line = str_replace(
                        '/misc/share/ssl',
                        rtrim($this->env->getSslCertificatesDir(), DIRECTORY_SEPARATOR),
                        $line
                    );
                }

                if (PHP_OS === 'Darwin') {
                    $line = str_replace(
                        [
                            'user: docker:docker',
                            'sysctls:',
                            '- net.ipv4.ip_unprivileged_port_start=0'
                        ],
                        [
                            '#user: docker:docker',
                            '#sysctls:',
                            '#- net.ipv4.ip_unprivileged_port_start=0'
                        ],
                        $line
                    );
                }

                $newContent .= $line;
                // @TODO: should we handle current user ID and modify Dockerfile to allow different UID/GUID?
            }

            fclose($fileHandle);

            file_put_contents($file, $newContent);
        }
    }

    /**
     * @param array $files
     * @param array $domains
     * @param array $sslCertificateFiles
     * @param string $webRoot
     * @return void
     */
    public function processVirtualHostConf(
        array $files,
        array $domains,
        array $sslCertificateFiles,
        string $webRoot = ''
    ): void {
        $virtualHostConfigurationFiles = array_filter($files, static function ($file) {
            return preg_match('/virtual-host.*\.conf/', $file);
        });

        if (count($virtualHostConfigurationFiles) !== 1) {
            throw new \RuntimeException(
                'Only one virtual host file supported: ' . implode(', ', $virtualHostConfigurationFiles)
            );
        }

        $virtualHostConfigurationFile = array_pop($virtualHostConfigurationFiles);
        $sslCertificateFile = $sslCertificateFiles[Filesystem::SSL_CERTIFICATE_FILE];
        $sslCertificateKeyFile = $sslCertificateFiles[Filesystem::SSL_CERTIFICATE_KEY_FILE];
        $fileHandle = fopen($virtualHostConfigurationFile, 'rb');
        $newContent = '';

        while ($line = fgets($fileHandle)) {
            if (strpos($line, 'ServerName') !== false) {
                $newContent .= sprintf("    ServerName %s\n", $domains[0]);
                continue;
            }

            if (strpos($line, 'ServerAlias') !== false) {
                $newContent .= sprintf(
                    "    ServerAlias %s\n",
                    implode(' ', array_slice($domains, 1))
                );
                continue;
            }

            if (strpos($line, 'SSLCertificateFile') !== false) {
                $newContent .= "        SSLCertificateFile /certs/$sslCertificateFile\n";
                continue;
            }

            if (strpos($line, 'SSLCertificateKeyFile') !== false) {
                $newContent .= "        SSLCertificateKeyFile /certs/$sslCertificateKeyFile\n";
                continue;
            }

            if (strpos($line, 'DocumentRoot') !== false || strpos($line, '<Directory ') !== false) {
                $newContent .= str_replace('pub/', $webRoot, $line);
                continue;
            }

            $newContent .= $line;
        }

        fclose($fileHandle);

        file_put_contents($virtualHostConfigurationFile, $newContent);
    }

    /**
     * @param array $filesToDenyAccessTo
     * @param bool $exceptionIfNotExists
     * @return void
     * @throws FilesystemException
     */
    public function processHtaccess(array $filesToDenyAccessTo, bool $exceptionIfNotExists = true): void
    {
        if (!file_exists('.htaccess')) {
            if ($exceptionIfNotExists) {
                throw new FilesystemException('The file ".htaccess" does not exist in this folder.');
            }

            return;
        }

        $htaccess = (string) file_get_contents('.htaccess');
        $additionalAccessRules = '';

        foreach ($filesToDenyAccessTo as $file) {
            if (strpos($htaccess, $file) === false && strpos($file, '/') === false) {
                $additionalAccessRules .= <<<HTACCESS

                    <Files $file>
                        <IfVersion < 2.4>
                            order allow,deny
                            deny from all
                        </IfVersion>
                        <IfVersion >= 2.4>
                            Require all denied
                        </IfVersion>
                    </Files>
                HTACCESS;
            }
        }

        if ($additionalAccessRules) {
            file_put_contents('.htaccess', "\n$additionalAccessRules", FILE_APPEND);
        }
    }

    /**
     * @param array $sslCertificateFiles
     * @return void
     */
    public function processTraefikRules(array $sslCertificateFiles): void
    {
        // Do not remove old certs because other websites may use it
        $traefikRules = file_get_contents($this->filesystem->getTraefikRulesFile());
        $sslCertificateFile = $sslCertificateFiles[Filesystem::SSL_CERTIFICATE_FILE];
        $sslCertificateKeyFile = $sslCertificateFiles[Filesystem::SSL_CERTIFICATE_KEY_FILE];

        if (strpos($traefikRules, $sslCertificateFile) === false) {
            file_put_contents(
                $this->filesystem->getTraefikRulesFile(),
                <<<TOML


                [[tls]]
                  entryPoints = ["https", "grunt"]
                  [tls.certificate]
                    certFile = "/certs/$sslCertificateFile"
                    keyFile = "/certs/$sslCertificateKeyFile"
                TOML,
                FILE_APPEND
            );
        }
    }

    /**
     * Add domain to /etc/hosts if not there for 127.0.0.1
     * @param array $domains
     * @return void
     */
    public function processHosts(array $domains): void
    {
        $hostsFileHandle = fopen('/etc/hosts', 'rb');
        $existingDomains = [];

        while ($line = fgets($hostsFileHandle)) {
            $isLocalhost = false;

            foreach ($lineParts = explode(' ', $line) as $string) {
                $string = trim($string); // remove line endings
                $string = trim($string, '#'); // remove comments

                if (!$isLocalhost && strpos($string, '127.0.0.1') !== false) {
                    $isLocalhost = true;
                }

                if ($isLocalhost && $this->domainValidator->isValid($string)) {
                    $existingDomains[] = $string;
                }
            }
        }

        fclose($hostsFileHandle);

        if ($domainsToAdd = array_diff($domains, $existingDomains)) {
            $hosts = '127.0.0.1 ' . implode(' ', $domainsToAdd);
            $this->shell->sudoPassthru("echo '$hosts' | sudo tee -a /etc/hosts");
        }
    }
}
