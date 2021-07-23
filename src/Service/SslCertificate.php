<?php
declare(strict_types=1);

namespace App\Service;

class SslCertificate
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * SslCertificate constructor.
     * @param \App\Config\Env $env
     * @param Shell $shell
     * @param Filesystem $filesystem
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\Service\Filesystem $filesystem
    ) {
        $this->env = $env;
        $this->shell = $shell;
        $this->filesystem = $filesystem;
    }

    /**
     * Array keys array containing SSL certificate file names
     */
    public const SSL_CERTIFICATE_FILE = 'ssl_certificate_file';
    public const SSL_CERTIFICATE_KEY_FILE = 'ssl_certificate_key_file';

    /**
     * @param array $domains
     * @param string|null $certificateName
     * @return array
     */
    public function generateSslCertificates(array $domains, ?string $certificateName = null): array
    {
        $sslCertificateDir = $this->env->getSslCertificatesDir();
        $sslCertificateFile = $sslCertificateKeyFile = '';
        $this->shell->passthru(
            $this->getMkcertCommand(
                $domains,
                $certificateName,
                $sslCertificateFile,
                $sslCertificateKeyFile
            ) . ' 2>/dev/null',
            false,
            $sslCertificateDir
        );

        $result = [
            self::SSL_CERTIFICATE_FILE => $sslCertificateFile,
            self::SSL_CERTIFICATE_KEY_FILE => $sslCertificateKeyFile,
        ];

        if (
            !$this->filesystem->isWritableFile($sslCertificateDir . $sslCertificateFile)
            || !$this->filesystem->isWritableFile($sslCertificateDir . $sslCertificateKeyFile)
        ) {
            throw new \RuntimeException('Unable to generate SSL certificates for the project');
        }

        return $result;
    }

    /**
     * @param array $domains
     * @param string|null $certificateName
     * @param string|null $sslCertificateFile
     * @param string|null $sslCertificateKeyFile
     * @return string
     */
    public function getMkcertCommand(
        array $domains,
        ?string $certificateName = null,
        ?string &$sslCertificateFile = '',
        ?string &$sslCertificateKeyFile = ''
    ): string {
        $additionalDomainsCount = count($domains) - 1;
        $certificateName = $certificateName ?? $domains[0];
        $sslCertificateFile = sprintf(
            '%s%s.pem',
            $certificateName,
            $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
        );
        $sslCertificateKeyFile = sprintf(
            '%s%s-key.pem',
            $certificateName,
            $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
        );
        $domainsString = implode(' ', $domains);

        return "mkcert -cert-file=$sslCertificateFile -key-file=$sslCertificateKeyFile $domainsString";
    }
}