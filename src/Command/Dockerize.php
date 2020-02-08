<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\MysqlContainer;
use App\CommandQuestion\Question\PhpVersion;
use App\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 */
class Dockerize extends AbstractCommand
{
    public const OPTION_PATH = 'path';

    public const OPTION_WEB_ROOT = 'webroot';

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
        // @TODO: add MySQL version
        $this->setName('dockerize')
            ->addOption(
                self::OPTION_PATH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Project root path (current folder if not specified). Mostly for internal use by the `setup:magento`.'
            )->addOption(
                self::OPTION_WEB_ROOT,
                null,
                InputOption::VALUE_OPTIONAL,
                'Web Root'
            );
        $this->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings.
You will be asked to enter production domains, choose PHP version and web root folder.
You will be asked to add more environments for staging/text/development/etc. environments with the same or new domains.
If you made a mistype in the PHP version or domain names - re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>/usr/bin/php7.3 /misc/apps/dockerizer_for_php/bin/console %command.full_name%</info>

Example usage with PHP version, MySQL container and with domains, without questions when possible
(non-interactive mode) and without adding more environments:

    <info>/usr/bin/php7.3 /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=7.3 --mysql-container=mysql57 --domains='example.com www.example.com' -n</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=5.6 --mysql-container=mysql56 --domains='example.com www.example.com' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
The file `/etc/hosts` is not populated automatically!
EOF
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    public function getQuestions(): array
    {
        return [
            PhpVersion::QUESTION,
            MysqlContainer::QUESTION,
            Domains::QUESTION
        ];
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = 0;
        $cwd = getcwd();

        try {
            // 1. Use current folder as a project root, update permissions (in case there is something owned by root)
            if ($projectRoot = trim((string) $input->getOption(self::OPTION_PATH))) {
                $projectRoot = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR;
                chdir($projectRoot);
            }

            $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
            $currentUser = get_current_user();
            $this->shell->sudoPassthru("chown -R $currentUser:$currentUser ./");

            // 2. Get PHP version, copy files for docker-compose
            /** @var PhpVersion $phpVersionQuestion */
            $phpVersion = $this->ask(PhpVersion::QUESTION, $input, $output);
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

            $phpDockerfilesDir = $this->filesystem->getDir(Filesystem::DIR_PHP_DOCKERFILES);
            // We will have multiple Dockerfiles in the future....
            $this->shell->passthru(<<<BASH
                rm ./docker/Dockerfile
                cp {$phpDockerfilesDir}{$phpVersion}/Dockerfile ./docker/Dockerfile
            BASH);

            // 3. Get MySQL container to connect link composition
            $mysqlContainer = $this->ask(MysqlContainer::QUESTION, $input, $output);

            // 4. Domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask(Domains::QUESTION, $input, $output);

            // @TODO: move generating certificates to a separate class, collect domains from labels in the automated way
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

            // 5. Document root
            // @TODO: move to a separate question?
            if (!$webRoot = $input->getOption(self::OPTION_WEB_ROOT)) {
                $question = new Question(<<<TEXT
                    Default web root is 'pub/'
                    Leave empty to use default, enter new web root or enter '/' for current folder:
                TEXT);

                $webRoot = trim((string) $this->getHelper('question')->ask($input, $output, $question));

                if (!$webRoot) {
                    $webRoot = 'pub/';
                } elseif ($webRoot === '/') {
                    $webRoot = '';
                } else {
                    $webRoot = trim($webRoot, '/') . '/';
                }
            }

            if (!is_dir($webRoot)) {
                throw new \InvalidArgumentException('Web root directory is not valid');
            }

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

                    if (strpos($line, 'mysql57:mysql') !== false) {
                        $line = str_replace('mysql57', $mysqlContainer, $line);
                    }

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

                    if ((strpos($line, 'DocumentRoot') !== false) || (strpos($line, '<Directory ') !== false)) {
                        $newContent .= str_replace('pub/', $webRoot, $line);
                        continue;
                    }

                    // MacOS-specific replacements to get the things work, but without the `docker-sync-stack`
                    // @TODO: take needed things from the DV.Campus
                    if (PHP_OS === 'Darwin' && strpos($line, '/misc/share/ssl') !== false) {
                        $line = str_replace(
                            '/misc/share/ssl',
                            rtrim($this->env->getSslCertificatesDir(), DIRECTORY_SEPARATOR),
                            $line
                        );
                    }

                    $newContent .= $line;
                    // @TODO: handle current user ID and modify Dockerfile
                }

                fclose($fileHandle);

                file_put_contents($file, $newContent);
            }

            $this->shell->passthru('mkdir -p var/log');

            if (!is_dir('var/log')) {
                $output->writeln('<error>Can not create log dir "var/log/". Container may not run properly because '
                    . 'the web server is not able to write logs!</error>');
            }

            // will not exist on first dockerization while installing clean Magento
            if (file_exists('.htaccess')) {
                $htaccess = file_get_contents('.htaccess');
                $additionalAccessRules = '';

                foreach ($projectTemplateFiles as $file) {
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
                    file_put_contents('.htaccess', "\n\n$additionalAccessRules", FILE_APPEND);
                }
            }

            $domainsString = implode(' ', $domains);
            $this->shell->passthru(<<<BASH
                cd {$this->env->getSslCertificatesDir()}
                mkcert $domainsString
BASH
            );

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
TOML
                    ,
                    FILE_APPEND
                );
            }
        } catch (\Exception $e) {
            $exitCode = 1;
            $output->writeln("<error>{$e->getMessage()}</error>");
        } finally {
            chdir($cwd);
        }

        return $exitCode;
    }
}
