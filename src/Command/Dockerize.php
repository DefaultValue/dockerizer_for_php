<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\PhpVersion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 */
class Dockerize extends AbstractCommand
{
    private const TRAEFIK_RULES_FILE = 'docker_infrastructure/local_infrastructure/traefik_rules/rules.toml';

    public const OPTION_PATH = 'path';

    public const OPTION_WEB_ROOT = 'webroot';

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('dockerize')
            ->addOption(
                self::OPTION_PATH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Project root path (current folder if not specified)'
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

    <info>/usr/bin/php7.3 /misc/apps/dockerizer_for_php/bin/console dockerize</info>

Example usage with PHP version and with domains, in the non-interactive mode (without adding more environments):

    <info>/usr/bin/php7.3 /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.3 --prod='example.com www.example.com' -n</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=5.6 --domains='example.com www.example.com' --webroot='/' -n</info>

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
            if (!file_exists($this->getTraefikRulesFile())) {
                throw new \RuntimeException(<<<TEXT
                    Missing Traefik SSL configuration file: {$this->getTraefikRulesFile()}
                    Maybe infrastructure has not been set up yet
                TEXT);
            }

            // 1. Project root - current folder
            if ($path = $input->getOption(self::OPTION_PATH)) {
                chdir($path);
            }

            $currentUser = get_current_user();
            $this->sudoPassthru("chown -R $currentUser: ./");

            // 2. PHP version - choose or pass here
            /** @var PhpVersion $phpVersionQuestion */
            $phpVersion = $this->ask($input, $output, PhpVersion::QUESTION);

            $dockerFiles = array_merge(
                array_filter(glob(
                    $this->env->getDir('docker_infrastructure/templates/project/{,.}[!.,!..]*'),
                    GLOB_MARK | GLOB_BRACE
                ), 'is_file'),
                array_filter(glob(
                    $this->env->getDir('docker_infrastructure/templates/project/docker/{,.}[!.,!..]*'),
                    GLOB_MARK | GLOB_BRACE
                ), 'is_file')
            );

            $dockerFiles = array_filter($dockerFiles, static function ($file) {
                return strpos($file, '.DS_Store') === false;
            });

            $templatesDir = $this->env->getDir('docker_infrastructure/templates/project/');

            array_walk($dockerFiles, static function (&$value) use ($templatesDir) {
                $value = str_replace($templatesDir, '', $value);
            });

            foreach ($dockerFiles as $file) {
                @unlink($file);
            }

            $phpTemplatesDir = $this->env->getDir('docker_infrastructure/templates/php');

            passthru(<<<BASH
                cp -r {$this->env->getDir('docker_infrastructure/templates/project/*')} ./
                rm ./docker/Dockerfile
                cp $phpTemplatesDir/$phpVersion/Dockerfile ./docker/Dockerfile
            BASH);

            // 3. Domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask($input, $output, Domains::QUESTION);

            // @TODO: move generating certificates to a separate class, collect domains from labels in the automatic way
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

            foreach ($dockerFiles as $file) {
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
                            'example-dev.com,www.example-dev.com,example-2-dev.com,www.example-2-dev.com',
                            'example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com',
                            'example-dev.com'
                        ],
                        [
                            implode(',', $productionDomains),
                            implode(' ', $productionDomains),
                            $productionDomains[0],
                            implode(',', $developmentDomains),
                            implode(' ', $developmentDomains),
                            $developmentDomains[0]
                        ],
                        $line
                    );

                    if (strpos($line, 'ServerAlias') !== false) {
                        $newContent .= sprintf(
                            "    ServerAlias %s\n",
                            implode(' ', array_slice($allDomains, 1))
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

                    if (PHP_OS === 'Darwin') { // MacOS
                        if (strpos($line, '/misc/') !== false) {
                            $line = str_replace(
                                '/misc/share/ssl',
                                rtrim($this->env->getSslCertificatesDir(), DIRECTORY_SEPARATOR),
                                $line
                            );
                        }
                    }

                    $newContent .= $line;

                    // @TODO: handle current user ID and modify Dockerfile
                }

                fclose($fileHandle);

                file_put_contents($file, $newContent);
            }

            if (!mkdir('var') && !is_dir('var') && !mkdir('var/log') && !is_dir('var/log')) {
                $output->writeln('<error>Can not create log dir "var/log/". Container may not run properly because '
                    . 'the web server is not able to write logs!</error>');
            }

            // will not exist on first dockerization while installing clean Magento
            if (file_exists('.htaccess')) {
                $htaccess = file_get_contents('.htaccess');
                $additionalAccessRules = '';

                foreach ($dockerFiles as $file) {
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

            $allDomainsString = implode(' ', $allDomains);
            passthru(<<<BASH
                cd {$this->env->getSslCertificatesDir()}
                mkcert $allDomainsString
BASH
            );

            $traefikRules = file_get_contents($this->getTraefikRulesFile());

            if (strpos($traefikRules, $certificateFile) === false) {
                file_put_contents(
                    $this->getTraefikRulesFile(),
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

    /**
     * @return string
     */
    private function getTraefikRulesFile(): string
    {
        return $this->env->getDir(self::TRAEFIK_RULES_FILE);
    }
}
