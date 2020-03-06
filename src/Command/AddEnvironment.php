<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\Domains;
use App\Service\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 */
class AddEnvironment extends AbstractCommand
{
    public const OPTION_PATH = 'path';

    public const ARGUMENT_ENVIRONMENT_NAME = 'environment_name';

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Filesystem $filesystem
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Filesystem $filesystem,
        $name = null
    ) {
        $this->filesystem = $filesystem;
        parent::__construct($env, $shell, $questionPool, $name);
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('env:add')
            ->addArgument(
                self::ARGUMENT_ENVIRONMENT_NAME,
                InputArgument::REQUIRED,
                'Environment name'
            )->addOption(
                // Not really great to have this constant here
                SetUpMagento::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Overwrite environment file'
            );
//            ->addOption(
//                self::OPTION_PATH,
//                null,
//                InputOption::VALUE_OPTIONAL,
//                'Project root path (current folder if not specified). Mostly for internal use by the `setup:magento`.'
//            );
        $this->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings.
You will be asked to enter production domains, choose PHP version and web root folder.
You will be asked to add more environments for staging/text/development/etc. environments with the same or new domains.
If you made a mistype in the PHP version or domain names - re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name%</info>

Example usage with PHP version, MySQL container and with domains, without questions when possible
(non-interactive mode) and without adding more environments:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=7.3 --mysql-container=mysql57 --domains='example.com www.example.com' -n</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=5.6 --mysql-container=mysql56 --domains='example.com www.example.com' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
The file `/etc/hosts` is not populated automatically!
EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    public function getQuestions(): array
    {
        return [
            Domains::QUESTION
        ];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // php /misc/apps/dockerizer_for_php/bin/console env:add staging
        $exitCode = 0;

        try {
            // 0. Use current folder as a project root.
            $projectRoot = getcwd() . DIRECTORY_SEPARATOR;

            // 1. Get env name. Ensure it does not exist proceed if -f
            $envFileName = "docker-compose-{$input->getArgument(self::ARGUMENT_ENVIRONMENT_NAME)}.yml";
            $envFilePath = $projectRoot . $envFileName;


            if ($this->filesystem->isWritableFile($envFilePath) && !$input->getOption(SetUpMagento::OPTION_FORCE)) {
                throw new \InvalidArgumentException(
                    "Environment file '$envFileName' already exists. Please, enter other env name, remove it or use -f"
                );
            }



            // 3. Gather container mane from the main file
//            preg_match(, file_get_contents('docker-compose.yml'));
            if (!preg_match('/container_name.*\n/', <<<TEXT
                version: '3.7'
                services:
                  php-apache:
                    container_name: test.local
                    container_name: tets-2.local
                    build:
                      context: .
                      dockerfile: docker/Dockerfile
                      args:
                  container_name: yet-another-test.local
            TEXT, $mainContainerName)
            ) {
                throw new \RuntimeException('Can\'t find "container_name" in the "docker-compose.yml" file.');
            }

            $mainContainerName = '';

exit(0);
            // 2. Get env domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask(Domains::QUESTION, $input, $output);


            // 2. Copy compose file and rename it.
            // 3. Update container name and configs
            // 4. generate new cert from all domains - do not remove old because other websites may use it
            // 5. upgrade traefik conf - do not remove old because other websites may use it
            // 6. update virtual_host.conf
            // 7. Update hosts file





            // 2. Get PHP version, copy files for docker-compose
            $projectTemplateFiles = $this->filesystem->getProjectTemplateFiles();
            $projectTemplateDir = $this->filesystem->getDir(Filesystem::DIR_PROJECT_TEMPLATE);

            foreach ($projectTemplateFiles as $file) {
                @unlink($file);
                $templateFile = $projectTemplateDir . $file;

                if (strpos($file, DIRECTORY_SEPARATOR) !== false) {
                    $this->shell->passthru('mkdir -p ' . dirname($projectRoot . $file));
                }

                $this->shell->passthru("cp -r $templateFile $file");
            }

            // @TODO: Move to a new service for processing env files
            $additionalDomainsCount = count($domains) - 1;
            $certificateFile = sprintf(
                '%s%s.pem',
                $domains[0],
                $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
            );
            $certificateKeyFile = sprintf(
                '%s%s-key.pem',
                $domains[0],
                $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
            );

            // 6. Update files
            foreach ($projectTemplateFiles as $file) {
                $newContent = '';

                $fileHandle = fopen($file, 'rb');

                while ($line = fgets($fileHandle)) {
                    // mkcert
                    if (strpos($line, 'mkcert') !== false) {
                        $newContent .= sprintf("# $ mkcert %s\n", implode(' ', $domains));
                        continue;
                    }

                    $line = str_replace(
                        [
                            'example.com,www.example.com,example-2.com,www.example-2.com',
                            'example.com www.example.com example-2.com www.example-2.com',
                            'example.com',
//                            'example-dev.com,www.example-dev.com,example-2-dev.com,www.example-2-dev.com',
//                            'example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com',
//                            'example-dev.com'
                        ],
                        [
                            implode(',', $domains),
                            implode(' ', $domains),
                            $domains[0],
//                            implode(',', $developmentDomains),
//                            implode(' ', $developmentDomains),
//                            $developmentDomains[0]
                        ],
                        $line
                    );

                    if (strpos($line, 'ServerAlias') !== false) {
                        $newContent .= sprintf(
                            "    ServerAlias %s\n",
                            implode(' ', array_slice($domains, 1))
                        );
                        continue;
                    }

                    if (strpos($line, 'SSLCertificateFile') !== false) {
                        $newContent .= "        SSLCertificateFile /certs/$certificateFile\n";
                        continue;
                    }

                    if (strpos($line, 'SSLCertificateKeyFile') !== false) {
                        $newContent .= "        SSLCertificateKeyFile /certs/$certificateKeyFile\n";
                        continue;
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

            // will not exist on first dockerization while installing clean Magento
            if (file_exists('.htaccess')) {
                $htaccess = file_get_contents('.htaccess');
                $additionalAccessRules = '';

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

                if ($additionalAccessRules) {
                    file_put_contents('.htaccess', "\n\n$additionalAccessRules", FILE_APPEND);
                }
            }

            $domainsString = implode(' ', $domains);
            $this->shell->passthru(<<<BASH
                cd {$this->env->getSslCertificatesDir()}
                mkcert $domainsString
            BASH);

            $traefikRules = file_get_contents($this->filesystem->getTraefikRulesFile());

            if (strpos($traefikRules, $certificateFile) === false) {
                file_put_contents(
                    $this->filesystem->getTraefikRulesFile(),
                    <<<TOML


                    [[tls]]
                      entryPoints = ["https", "grunt"]
                      [tls.certificate]
                        certFile = "/certs/$certificateFile"
                        keyFile = "/certs/$certificateKeyFile"
                    TOML,
                    FILE_APPEND
                );
            }
        } catch (\Exception $e) {
            $exitCode = 1;
            $output->writeln("<error>{$e->getMessage()}</error>");
        }

        return $exitCode;
    }
}
