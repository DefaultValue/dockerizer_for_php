<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 * @package App\Command
 */
class Dockerize extends \Symfony\Component\Console\Command\Command
{
    private const TRAEFIK_RULES_FILE = '/misc/apps/docker_infrastructure/local_infrastructure/traefik_rules/rules.toml';

    public const OPTION_PATH = 'path';

    public const OPTION_PHP_VERSION = 'php';

    public const OPTION_PRODUCTION_DOMAINS = 'prod';

    public const OPTION_DEVELOPMENT_DOMAINS = 'dev';

    public const OPTION_WEB_ROOT = 'webroot';

    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \App\Service\DomainValidator $domainValidator
     */
    private $domainValidator;

    /**
     * @var \App\CommandQuestion\PhpVersion $phpVersion
     */
    private $phpVersion;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\DomainValidator $domainValidator
     * @param \App\CommandQuestion\PhpVersion $phpVersion
     * @param string|null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\DomainValidator $domainValidator,
        \App\CommandQuestion\PhpVersion $phpVersion,
        string $name = null
    ) {
        parent::__construct($name);
        $this->env = $env;
        $this->domainValidator = $domainValidator;
        $this->phpVersion = $phpVersion;
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('dockerize')
            ->addOption(self::OPTION_PATH, null, InputOption::VALUE_OPTIONAL, 'Project root path (current folder if not specified)')
            ->addOption(self::OPTION_PHP_VERSION, null, InputOption::VALUE_OPTIONAL, 'PHP version: 5.6, 7.3, etc.')
            ->addOption(self::OPTION_PRODUCTION_DOMAINS, null, InputOption::VALUE_OPTIONAL, 'Production domains list (space-separated)')
            ->addOption(self::OPTION_DEVELOPMENT_DOMAINS, null, InputOption::VALUE_OPTIONAL, 'Development domains list (space-separated)')
            ->addOption(self::OPTION_WEB_ROOT, null, InputOption::VALUE_OPTIONAL, 'Web Root')
            ->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings. You will be asked to enter production/development domains, choose PHP version and web root folder.
Development domains can be left empty if they are not needed.
If you made a mistype in the PHP version or domain names - just re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize</info>

Example usage without development domains:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com' --dev=''</info>

Example usage with development domains:
    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com example-2.com www.example-2.com' --dev='example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com'</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=5.6 --prod='example.com www.example.com' --dev='' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them. The file `/etc/hosts` is not populated automatically.
EOF
            );
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
            if (!file_exists(self::TRAEFIK_RULES_FILE)) {
                $file = self::TRAEFIK_RULES_FILE;
                throw new \RuntimeException("Missing Traefik SSL configuration file: $file\nMaybe infrastructure has not been set up yet");
            }

            // 1. Project root - current folder or passed one for setup:magento command
            if ($path = $input->getOption(self::OPTION_PATH)) {
                chdir($path);
            }

            $currentUser = get_current_user();
            $this->sudoPassthru("chown -R $currentUser: ./");

            // 2. PHP version - choose or pass here
            $phpVersion = $this->phpVersion->ask($input, $output, $this->getHelper('question'));

            $dockerFiles = array_merge(
                array_filter(glob(
                    '/misc/apps/docker_infrastructure/templates/project/{,.}[!.,!..]*',
                    GLOB_MARK|GLOB_BRACE
                ), 'is_file'),
                array_filter(glob(
                    '/misc/apps/docker_infrastructure/templates/project/docker/{,.}[!.,!..]*',
                    GLOB_MARK|GLOB_BRACE
                ), 'is_file')
            );
            array_walk($dockerFiles, static function (&$value) {
                $value = str_replace('/misc/apps/docker_infrastructure/templates/project/', '', $value);
            });

            foreach ($dockerFiles as $file) {
                @unlink($file);
            }

            passthru(<<<BASH
                cp -r /misc/apps/docker_infrastructure/templates/project/* ./
                rm ./docker/Dockerfile
                cp /misc/apps/docker_infrastructure/templates/php/$phpVersion/Dockerfile ./docker/Dockerfile
BASH
            );

            // 3. Production domains
            if (!$productionDomains = $input->getOption(self::OPTION_PRODUCTION_DOMAINS)) {
                $question = new Question('Enter space-separated list of production domains including non-www and www version if needed: ');
                $productionDomains = $this->getHelper('question')->ask($input, $output, $question);

                if (!$productionDomains) {
                    throw new \InvalidArgumentException('Production domains list is empty!');
                }

            }

            if (!is_array($productionDomains)) {
                $productionDomains = explode(' ', $productionDomains);
            }

            $productionDomains = array_filter($productionDomains);

            foreach ($productionDomains as $domain) {
                if (!$this->domainValidator->isValid($domain)) {
                    throw new \InvalidArgumentException("Production domain is not valid: $domain");
                }
            }

            // 4. Development domains
            $developmentDomains = $input->getOption(self::OPTION_DEVELOPMENT_DOMAINS);

            if ($developmentDomains === null) {
                $question = new Question('Enter space-separated list of development domains: ');

                if ($developmentDomainsAnswer = $this->getHelper('question')->ask($input, $output, $question)) {
                    $developmentDomains = array_filter(explode(' ', $developmentDomainsAnswer));

                    foreach ($developmentDomains as $domain) {
                        if (!$this->domainValidator->isValid($domain)) {
                            throw new \InvalidArgumentException("Development domain is not valid: $domain");
                        }
                    }
                } else {
                    $output->writeln('<into>Development domains are not set. Proceeding without them...</into>');
                    $developmentDomains = [];
                }
            } elseif (empty($developmentDomains)) {
                $developmentDomains = [];
            } elseif (is_string($developmentDomains)) {
                $developmentDomains = array_filter(explode(' ', $developmentDomains));

                foreach ($developmentDomains as $domain) {
                    if (!$this->domainValidator->isValid($domain)) {
                        throw new \InvalidArgumentException("Development domain is not valid: $domain");
                    }
                }
            }

            // 5. Document root
            if (!$webRoot = $input->getOption(self::OPTION_WEB_ROOT)) {
                $question = new Question("Default web root is 'pub/'\nEnter new web root, enter '/' for current folder, leave empty to use default or enter new one: ");

                $webRoot = trim($this->getHelper('question')->ask($input, $output, $question));

                if (!$webRoot) {
                    $webRoot = 'pub/';
                } elseif ($webRoot === '/') {
                    $webRoot = '';
                } else {
                    $webRoot = trim($webRoot, '/') . '/';
                }
            }

            // 6. Replace in docker files
            $allDomains = array_filter(array_merge($productionDomains, $developmentDomains));
            $additionalDomainsCount = count($allDomains) - 1;
            $certificateFile = sprintf(
                '%s%s.pem',
                $allDomains[0],
                $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
            );
            $certificateKeyFile = sprintf(
                '%s%s-key.pem',
                $allDomains[0],
                $additionalDomainsCount ? "+$additionalDomainsCount"  : ''
            );

            // The worst code ever ( But fast to write since we do not have template
            foreach ($dockerFiles as $file) {
                $newContent = '';

                $fileHandle = fopen($file, 'rb');

                while ($line = fgets($fileHandle)) {
                    // mkcert
                    if (strpos($line, 'mkcert') !== false) {
                        $newContent .= sprintf("# $ mkcert %s\n", implode(' ', $allDomains));
                        continue;
                    }

                    // container name
                    if (strpos($line, 'container_name') !== false) {
                        $newContent .= sprintf("    container_name: %s\n", $productionDomains[0]);
                        continue;
                    }

                    // traefik - prod
                    if (strpos($line, '- traefik.http.frontend.rule=Host:example.com,') !== false) {
                        $newContent .= sprintf(
                            "      - traefik.http.frontend.rule=Host:%s\n",
                            implode(',', $productionDomains)
                        );
                        continue;
                    }

                    if (strpos($line, '- traefik.https.frontend.rule=Host:example.com,') !== false) {
                        $newContent .= sprintf(
                            "      - traefik.https.frontend.rule=Host:%s\n",
                            implode(',', $productionDomains)
                        );
                        continue;
                    }

                    // extra hosts - prod
                    if (strpos($line, '- "example.com') !== false) {
                        $newContent .= sprintf("      - \"%s:127.0.0.1\"\n", implode(' ', $productionDomains));
                        continue;
                    }

                    // traefik - dev
                    if (strpos($line, '- traefik.http.frontend.rule=Host:example-dev.com,') !== false) {
                        $domains = !empty($developmentDomains) ? $developmentDomains : $productionDomains;
                        $newContent .= sprintf("      - traefik.http.frontend.rule=Host:%s\n", implode(',', $domains));
                        continue;
                    }

                    if (strpos($line, '- traefik.https.frontend.rule=Host:example-dev.com,') !== false) {
                        $domains = !empty($developmentDomains) ? $developmentDomains : $productionDomains;
                        $newContent .= sprintf("      - traefik.https.frontend.rule=Host:%s\n", implode(',', $domains));
                        continue;
                    }

                    if (strpos($line, '- traefik.grunt.frontend.rule=Host:example-dev.com,') !== false) {
                        $domains = !empty($developmentDomains) ? $developmentDomains : $productionDomains;
                        $newContent .= sprintf("      - traefik.grunt.frontend.rule=Host:%s\n", implode(',', $domains));
                        continue;
                    }

                    // extra hosts - dev
                    if (strpos($line, '- "example-dev.com') !== false) {
                        $domains = !empty($developmentDomains) ? $developmentDomains : $productionDomains;
                        $newContent .= sprintf("      - \"%s:127.0.0.1\"\n", implode(' ', $domains));
                        continue;
                    }

                    // xdebug
                    if (strpos($line, 'PHP_IDE_CONFIG') !== false) {
                        $newContent .= sprintf("      - PHP_IDE_CONFIG=serverName=%s\n", $productionDomains[0]);
                        continue;
                    }

                    // Virtual host
                    if (strpos($line, 'ServerName') !== false) {
                        $newContent .= sprintf("    ServerName %s\n", $productionDomains[0]);
                        continue;
                    }

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

                    if (strpos($line, 'example.com') !== false) {
                        $line = str_replace('example.com', $productionDomains[0], $line);
                    }

                    $newContent .= $line;
                }

                fclose($fileHandle);

                file_put_contents($file, $newContent);
            }

            @mkdir('var');
            @mkdir('var/log');

            if (!is_dir('var/log')) {
                $output->writeln('<error>Can not create log dir "var/log/. Container may not run properly because web server is not able to write logs there!"</error>');
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
                cd /misc/share/ssl
                mkcert $allDomainsString
BASH
            );

            $traefikRules = file_get_contents(self::TRAEFIK_RULES_FILE);

            if (strpos($traefikRules, $certificateFile) === false) {
                file_put_contents(
                    self::TRAEFIK_RULES_FILE,
                    <<<TOML


[[tls]]
  entryPoints = ["https"]
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
     * @TODO: code duplication! Must move executing external commands to a separate class
     *
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command
     */
    protected function sudoPassthru($command): void
    {
        passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command");
    }
}
