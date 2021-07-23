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
     * @var \App\Service\SslCertificate $sslCertificate
     */
    private $sslCertificate;

    /**
     * FileProcessor constructor.
     * @param \App\Service\Filesystem $filesystem
     * @param Shell $shell
     * @param DomainValidator $domainValidator
     * @param SslCertificate $sslCertificate
     */
    public function __construct(
        \App\Service\Filesystem $filesystem,
        \App\Service\Shell $shell,
        \App\Service\DomainValidator $domainValidator,
        \App\Service\SslCertificate $sslCertificate
    ) {
        $this->filesystem = $filesystem;
        $this->shell = $shell;
        $this->domainValidator = $domainValidator;
        $this->sslCertificate = $sslCertificate;
    }

    /**
     * @TODO: MacOS - remove hosts mapping
     *
     * @param array $files
     * @param array $domains
     * @param string $applicationContainerName
     * @param string $mysqlContainer
     * @param string $phpVersion
     * @param int $composerVersion
     * @param string|null $elasticsearchVersion
     * @param string|null $executionEnvironment
     * @param string|null $virtualHostConfigurationFile
     */
    public function processDockerCompose(
        array $files,
        array $domains,
        string $applicationContainerName,
        string $mysqlContainer,
        string $phpVersion,
        int $composerVersion,
        ?string $elasticsearchVersion = null,
        ?string $executionEnvironment = null,
        ?string $virtualHostConfigurationFile = null
    ): void {
        $files = array_filter($files, static function ($file) {
            return preg_match('/docker-.*\.yml/', $file);
        });

        if ($executionEnvironment) {
            $dockerfile = $this->filesystem->copyDockerfile($phpVersion, $executionEnvironment);
        }

        foreach ($files as $file) {
            $content = str_replace(
                [
                    '`example.com`,`www.example.com`,`example-2.com`,`www.example-2.com`',
                    'mkcert example.com www.example.com example-2.com www.example-2.com',
                    'example.com www.example.com example-2.com www.example-2.com',
                    'container_name: example.com',
                    'serverName=example.com',
                    'example-com',
                    'mysql57:mysql',
                    'php:version',
                    'COMPOSER_VERSION=2'
                ],
                [
                    '`' . implode('`,`', $domains) . '`',
                    $this->sslCertificate->getMkcertCommand($domains, $applicationContainerName),
                    implode(' ', $domains),
                    "container_name: $applicationContainerName",
                    "serverName=$domains[0]",
                    str_replace('.', '-', $applicationContainerName),
                    "$mysqlContainer:mysql",
                    "php:$phpVersion",
                    "COMPOSER_VERSION=$composerVersion"
                ],
                file_get_contents($file)
            );

            if ($virtualHostConfigurationFile) {
                $content = str_replace(
                    './docker/virtual-host.conf',
                    "./docker/$virtualHostConfigurationFile",
                    $content
                );
            }

            // Fast implementation to support Magento 2.4.0. Plan that later compose files will become modular.
            if ($elasticsearchVersion && strpos($file, 'docker-compose') === 0) {
                $content .= <<<YAML

                  elasticsearch:
                    image: docker.elastic.co/elasticsearch/elasticsearch:$elasticsearchVersion
                    environment:
                      - network.host=0.0.0.0
                      - http.host=0.0.0.0
                      - transport.host=127.0.0.1
                      - xpack.security.enabled=false
                      - indices.query.bool.max_clause_count=10240
                      - ES_JAVA_OPTS=-Xms1024m -Xmx1024m
                    ulimits:
                      memlock:
                        soft: -1
                        hard: -1
                    restart: always
                    network_mode: bridge
                YAML;

                $content = str_replace(
                    [
                        '#    links:',
                        '#      - elasticsearch',
                    ],
                    [
                        '    links:',
                        '      - elasticsearch',
                    ],
                    $content
                );
            }

            if ($executionEnvironment) {
                $content = str_replace(
                    [
                        'image: defaultvalue/php',
                        '#    build:',
                        '#      context: .',
                        '#      dockerfile: docker/Dockerfile'
                    ],
                    [
                        '# image: defaultvalue/php',
                        '    build:',
                        '      context: .',
                        "      dockerfile: docker/$dockerfile"
                    ],
                    $content
                );
            }

            // If MacOS
            // if (PHP_OS === 'Darwin') {}
            $content = explode("\n", $content);

            // Remove top comments from all files except docker-compose.yml
            if ($file !== 'docker-compose.yml') {
                $content = str_replace(
                    '$ docker-compose up -d',
                    "$ docker-compose -f $file up -d",
                    $content
                );
            }

            file_put_contents($file, implode("\n", $content));
        }
    }

    /**
     * @param array $files
     * @param array $domains
     * @param array $sslCertificateFiles
     * @param string $webRoot
     * @param bool $processWebRoot - whether to process the web root (magento:setup needs this, `env:add` - not)
     * @return void
     */
    public function processVirtualHostConf(
        array $files,
        array $domains,
        array $sslCertificateFiles,
        string $webRoot = '',
        bool $processWebRoot = true
    ): void {
        $virtualHostConfigurationFiles = array_filter($files, static function ($file) {
            return preg_match('/virtual-host.*\.conf/', $file);
        });

        if (count($virtualHostConfigurationFiles) !== 1) {
            throw new \RuntimeException(
                'One virtual host per environment must be processed: ' . implode(', ', $virtualHostConfigurationFiles)
            );
        }

        $virtualHostConfigurationFile = array_pop($virtualHostConfigurationFiles);
        $sslCertificateFile = $sslCertificateFiles[SslCertificate::SSL_CERTIFICATE_FILE];
        $sslCertificateKeyFile = $sslCertificateFiles[SslCertificate::SSL_CERTIFICATE_KEY_FILE];
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

            if (
                $processWebRoot
                && (strpos($line, 'DocumentRoot') !== false || strpos($line, '<Directory ') !== false)
            ) {
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
        $sslCertificateFile = $sslCertificateFiles[SslCertificate::SSL_CERTIFICATE_FILE];
        $sslCertificateKeyFile = $sslCertificateFiles[SslCertificate::SSL_CERTIFICATE_KEY_FILE];

        if (strpos($traefikRules, $sslCertificateFile) === false) {
            file_put_contents(
                $this->filesystem->getTraefikRulesFile(),
                <<<TOML


                  [[tls.certificates]]
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

            foreach (explode(' ', $line) as $string) {
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
