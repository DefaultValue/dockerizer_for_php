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
 */
class Dockerize extends \Symfony\Component\Console\Command\Command
{
    private const TRAEFIK_RULES_FILE = 'docker_infrastructure/local_infrastructure/traefik_rules/rules.toml';

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
     * @var \App\CommandQuestion\PhpVersion $phpVersionQuestion
     */
    private $phpVersionQuestion;
    /**
     * @var \App\CommandQuestion\Domains
     */
    private $domainsQuestion;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\DomainValidator $domainValidator
     * @param \App\CommandQuestion\PhpVersion $phpVersionQuestion
     * @param \App\CommandQuestion\Domains $domainsQuestion
     * @param string|null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\DomainValidator $domainValidator,
        \App\CommandQuestion\PhpVersion $phpVersionQuestion,
        \App\CommandQuestion\Domains $domainsQuestion,
        string $name = null
    ) {
        parent::__construct($name);
        $this->env = $env;
        $this->domainValidator = $domainValidator;
        $this->phpVersionQuestion = $phpVersionQuestion;
        $this->domainsQuestion = $domainsQuestion;
    }

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
                self::OPTION_PHP_VERSION,
                null,
                InputOption::VALUE_OPTIONAL,
                'PHP version: 5.6, 7.3, etc.'
            )->addOption(
                self::OPTION_PRODUCTION_DOMAINS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Production domains list (space-separated)'
            )->addOption(
                self::OPTION_DEVELOPMENT_DOMAINS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Development domains list (space-separated)'
            )
            ->addOption(
                self::OPTION_WEB_ROOT,
                null,
                InputOption::VALUE_OPTIONAL,
                'Web Root'
            )
            ->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings.
You will be asked to enter production/development domains, choose PHP version and web root folder.
Development domains can be left empty if they are not needed.
If you made a mistype in the PHP version or domain names - re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize</info>

Example usage without development domains:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com' --dev=''</info>

Example usage with development domains:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com example-2.com www.example-2.com' --dev='example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com'</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console dockerize --php=5.6 --prod='example.com www.example.com' --dev='' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
The file `/etc/hosts` is not populated automatically.
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
            $phpVersion = $this->phpVersionQuestion->ask($input, $output, $this->getHelper('question'));

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

            // 3. Production domains
            if (!$productionDomains = $input->getOption(self::OPTION_PRODUCTION_DOMAINS)) {
                $question = new Question(
                    'Enter space-separated list of production domains including non-www and www version if needed: '
                );
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
            $this->domainsQuestion->ask(
                $input,
                $output,
                $this->getHelper('question'),
                $developmentDomains
            );

            // 5. Document root
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

            // 6. Replace in docker files
            // fix the case when development domains match production ones by a mistake
            $developmentDomains = array_filter(array_diff($developmentDomains, $productionDomains));
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

            $developmentDomains = !empty($developmentDomains) ? $developmentDomains : $productionDomains;

            foreach ($dockerFiles as $file) {
                $newContent = '';

                $fileHandle = fopen($file, 'rb');

                while ($line = fgets($fileHandle)) {
                    // mkcert
                    if (strpos($line, 'mkcert') !== false) {
                        $newContent .= sprintf("# $ mkcert %s\n", implode(' ', $allDomains));
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
                }

                fclose($fileHandle);

                file_put_contents($file, $newContent);
            }

            if (!mkdir('var') || !mkdir('var/log')) {
                $output->writeln('<error>Can not create log dir "var/log/". Container may not run properly because web server is not able to write logs there!</error>');
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
     * @TODO: code duplication! Must move executing external commands to a separate class
     *
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command
     */
    protected function sudoPassthru($command): void
    {
        passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command");
    }

    /**
     * @return string
     */
    private function getTraefikRulesFile(): string
    {
        return $this->env->getDir(self::TRAEFIK_RULES_FILE);
    }
}
