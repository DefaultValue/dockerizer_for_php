<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class SetUpMagento extends AbstractCommand
{
    public const OPTION_FORCE = 'force';

    private const MAGENTO_VERSION_TO_PHP_VERSION = [
        '2.0.0' => ['5.6', '7.0'],
        '2.1.0' => ['5.6', '7.0'],
        '2.2.0' => ['7.0', '7.1'],
        '2.3.0' => ['7.1', '7.2'],
        '2.3.3' => ['7.1', '7.2', '7.3'],
        '2.3.4' => ['7.2', '7.3']
    ];

    private const MAGENTO_REPOSITORY = 'https://%s:%s@repo.magento.com/';

    private const MAGENTO_PROJECT = 'magento/project-community-edition';

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('setup:magento')
//            ->addArgument(
//                'domains',
//                InputArgument::REQUIRED,
//                'Domain name without "www." and protocol'
//            )
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Semantic Magento version like 2.2.10, 2.3.2 etc.'
//            )->addOption(
//                Dockerize::OPTION_PHP_VERSION,
//                null,
//                InputOption::VALUE_OPTIONAL,
//                'PHP version - from 5.6 to 7.3'
            )->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Reinstall if the destination folder (domain name) is in use'
            )



            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command deploys clean Magento instance of the selected version into the defined folder.
You will be asked to select PHP version if it has not been provided.

Simple usage:

    <info>php bin/console setup:magento magento-232.local 2.3.2</info>

Install Magento with the pre-defined PHP version:

    <info>php bin/console %command.full_name% magento-232.local 2.3.2 --php=7.2</info>

Force install/reinstall Magento:
- with the latest supported PHP version;
- without questions;
- erase previous installation if the folder exists.

    <info>php bin/console %command.full_name% magento-232.local 2.3.2 -n -f</info>

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

        ];
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        try {
            $magentoVersion = $input->getArgument('version');

            if (((int) $magentoVersion[0]) !== 2 || substr_count($magentoVersion, '.') !== 2) {
                throw new \InvalidArgumentException(
                    'Magento version you\'ve entered does not follow semantic versioning and cannot be parsed!'
                );
            }

            $domain = trim($input->getArgument('domain'));

            if (strpos($domain, 'www.') === 0) {
                $output->writeln("<error>Stripping 'www.' from domain: $domain</error>");
                $domain = substr($domain, 4);
            }

            if (!$this->domainValidator->isValid($domain)) {
                throw new \InvalidArgumentException("Domain name is not valid: $domain");
            }

            $noInteraction = $input->getOption('no-interaction');
            $force = $input->getOption(self::OPTION_FORCE);

            $databaseName = $this->database->getDatabaseName($domain);
            $databaseUser = $this->database->getDatabaseUsername($domain);

            if (strlen($databaseName) < strlen($domain) || strlen($databaseUser) < strlen($domain)) {
                if (!$noInteraction) {
                    $question = new Question(<<<TEXT
Domain name is too long to use it for database username.
Database and user will be: $databaseName
Database user / password will be: $databaseUser / $databaseName
Enter "Y" to continue: 
TEXT
                    );

                    $proceedWithShortenedDbName = $this->getHelper('question')->ask($input, $output, $question);

                    if (!$proceedWithShortenedDbName || strtolower($proceedWithShortenedDbName) !== 'y') {
                        throw new \LengthException(
                            'You decided not to continue with this domains and database name. ' .
                            'Use shorter domain name if possible.'
                        );
                    }
                }
            }

            $projectRoot = $this->env->getDir($domain);

            // Set domain and project root only when all parameters are validated and confirmed, but before anything is created/deployed
            $this->setDomain($domain);
            $this->setProjectRoot($projectRoot);

            if (is_dir($projectRoot)) {
                if ($force) {
                    $this->cleanUp();
                } else {
                    $output->writeln(<<<TEXT
                        <error>Directory '$projectRoot' already exists. Can't deploy here.
                        Stop all containers (if any), remove the folder and re-run setup.
                        You can also use '-f' option to to force install Magento with this domain.</error>
                    TEXT);
                    return;
                }
            }

            if (!mkdir($projectRoot) || !is_dir($projectRoot)) {
                throw new \RuntimeException("Can't create directory: $projectRoot");
            }

            $authJson = json_decode(
                file_get_contents($this->env->getAuthJsonLocation()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $phpVersions = [];

            foreach (self::MAGENTO_VERSION_TO_PHP_VERSION as $m2platformVersion => $requiredPhpVersions) {
                if (version_compare($magentoVersion, $m2platformVersion, 'lt')) {
                    break;
                }

                $phpVersions = $requiredPhpVersions;
            }

            $phpVersion = $this->commandQuestionPhpVersion->ask(
                $input,
                $output,
                $this->getHelper('question'),
                $phpVersions,
                $noInteraction
            );

            // 1. Dockerize
            $this->dockerize($output, $phpVersion);
            // just in case previous setup was not successful
            $this->passthru("cd $projectRoot && docker-compose down 2>/dev/null");
            sleep(1); // Fails to reinstall after cleanup on MacOS. Let's wait a little and test if this helps

            // 2. Run container so that now we can run commands inside it
            if (PHP_OS === 'Darwin') { // MacOS
                $this->passthru(<<<BASH
                    cd $projectRoot
                    docker-compose -f docker-compose.yml up -d --build --force-recreate
BASH
                );
            } else {
                $this->passthru(<<<BASH
                    cd $projectRoot
                    docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --build --force-recreate
BASH
                );
            }

            // 3. Remove all Docker files so that the folder is empty
            $this->dockerExec('sh -c "rm -rf *"');

            // 4. Create Magento project
            $magentoRepositoryUrl = sprintf(
                self::MAGENTO_REPOSITORY,
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password']
            );
            $magentoCreateProject = sprintf(
                'create-project --repository=%s %s=%s /var/www/html',
                $magentoRepositoryUrl,
                self::MAGENTO_PROJECT,
                $input->getArgument('version')
            );

            $this->dockerExec("composer $magentoCreateProject");

            $this->passthru(<<<BASH
                cd $projectRoot
                git init
                git config core.fileMode false
                git config user.name docker
                git config user.email docker@example.com
                git add -A
                git commit -m "Initial commit" -q
BASH
            );

            // 5. Dockerize again so that we get all the same files and configs
            $this->dockerize($output, $phpVersion);
            $this->dockerExec('touch var/log/apache_error.log')
                ->dockerExec('chmod 777 -R generated/ pub/ var/ || :');

            $output->writeln('<info>Docker container should be ready. Trying to install Magento...</info>');

            $this->refreshDbAndInstall();

            $this->updateMagentoConfig();

            $this->dockerExec('php bin/magento cache:disable full_page block_html')
                ->dockerExec('php bin/magento deploy:mode:set developer')
                ->dockerExec('php bin/magento indexer:reindex');

            $this->copyAuthJson($projectRoot);

            $this->updateHosts();

            //@TODO: extend .gitignore and add .gitkeep to var/log/

            $output->writeln(<<<TEXT
<info>

*** Success! ***
Frontend: https://$domain
Admin Panel: https://$domain/admin/
</info>
TEXT
            );
        } catch (\Exception $e) {
            $this->cleanUp();
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
    }

    /**
     * Clean up the installation folder in case of exception or process termination
     */
    private function cleanUp(): void
    {
        if (!$this->getDomain() || !$this->getProjectRoot()) {
            return;
        }

        if (is_dir($this->getProjectRoot())) {
            // chown to be sure that the files are deletable
            $currentUser = get_current_user();

            passthru("cd {$this->getProjectRoot()} && docker-compose down 2>/dev/null");
            $this->sudoPassthru("chown -R $currentUser: {$this->getProjectRoot()}");
            passthru("rm -rf {$this->getProjectRoot()}");
        }

        $this->database->dropDatabase($this->getDomain());
    }

    /**
     * @param OutputInterface $output
     * @param string $phpVersion
     * @throws \Exception
     */
    private function dockerize(
        OutputInterface $output,
        string $phpVersion
    ): void {
        $dockerize = $this->getApplication()->find('dockerize');

        $arguments = [
            'command' => 'dockerize',
            '--' . Dockerize::OPTION_PATH => $this->getProjectRoot(),
            '--' . Dockerize::OPTION_PHP_VERSION => $phpVersion,
            '--' . Dockerize::OPTION_PRODUCTION_DOMAINS => [$this->getDomain(), "www.{$this->getDomain()}"],
            '--' . Dockerize::OPTION_DEVELOPMENT_DOMAINS => [],
            '--' . Dockerize::OPTION_WEB_ROOT => 'pub/'
        ];

        $dockerizeInput = new ArrayInput($arguments);

        if ($dockerize->run($dockerizeInput, $output)) {
            throw new \RuntimeException('Can\'t dockerize the project!');
        }
    }

    /**
     * Add domain to /etc/hosts if not there for 127.0.0.1
     */
    private function updateHosts(): void
    {
        $hostsFileHandle = fopen('/etc/hosts', 'rb');
        $domains = [];

        while ($line = fgets($hostsFileHandle)) {
            $isLocalhost = false;

            foreach ($lineParts = explode(' ', $line) as $string) {
                $string = trim($string); // remove line endings
                $string = trim($string, '#'); // remove comments

                if (!$isLocalhost && strpos($string, '127.0.0.1') !== false) {
                    $isLocalhost = true;
                }

                if ($isLocalhost && $this->domainValidator->isValid($string)) {
                    $domains[] = $string;
                }
            }
        }

        fclose($hostsFileHandle);

        if ($newDomains = array_diff([$this->getDomain(), "www.{$this->getDomain()}"], $domains)) {
            $hosts = '127.0.0.1 ' . implode(' ', $newDomains);
            $this->sudoPassthru("echo '$hosts' | sudo tee -a /etc/hosts");
        }
    }
}
