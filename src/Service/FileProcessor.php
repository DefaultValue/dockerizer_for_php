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
     * @var \App\Config\Env
     */
    private $env;

    /**
     * FileProcessor constructor.
     * @param \App\Config\Env $env
     */
    public function __construct(
        \App\Config\Env $env
    ) {
        $this->env = $env;
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
                // @TODO: handle current user ID and modify Dockerfile to allow different UID/GUID?
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

            if ($webRoot && ((strpos($line, 'DocumentRoot') !== false) || (strpos($line, '<Directory ') !== false))) {
                $newContent .= str_replace('pub/', $webRoot, $line);
                continue;
            }

            $newContent .= $line;
        }

        fclose($fileHandle);

        file_put_contents($virtualHostConfigurationFile, $newContent);
    }

    public function processHtaccess(array $filesToDenyAccessTo)
    {

    }
}
