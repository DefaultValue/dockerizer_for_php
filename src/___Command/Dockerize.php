<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\ComposerVersion;
use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\MysqlContainer;
use App\CommandQuestion\Question\PhpVersion;
use App\CommandQuestion\Question\ProjectMountRoot;
use App\CommandQuestion\Question\WebRoot;
use App\Config\Env;
use App\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 */
class Dockerize extends AbstractCommand
{
    public const OPTION_PATH = 'path';

    public const OPTION_ELASTICSEARCH = 'elasticsearch';

    public const OPTION_EXECUTION_ENVIRONMENT = 'execution-environment';

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * @var \App\Service\FileProcessor
     */
    private $fileProcessor;

    /**
     * @var \App\Service\SslCertificate $sslCertificate
     */
    private $sslCertificate;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Filesystem $filesystem
     * @param \App\Service\FileProcessor $fileProcessor
     * @param \App\Service\SslCertificate $sslCertificate
     * @param ?string $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        \App\Service\SslCertificate $sslCertificate,
        ?string $name = null
    ) {
        parent::__construct($env, $shell, $questionPool, $name);
        $this->filesystem = $filesystem;
        $this->fileProcessor = $fileProcessor;
        $this->sslCertificate = $sslCertificate;
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
                'Project root path (current folder if not specified). Mostly for internal use by the `magento:setup`.'
            )->addOption(
                self::OPTION_ELASTICSEARCH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Elasticsearch service version (https://hub.docker.com/_/elasticsearch)'
            )->addOption(
                self::OPTION_EXECUTION_ENVIRONMENT,
                'e',
                InputOption::VALUE_OPTIONAL,
                'Use local Dockerfile from the Docker Infrastructure repository instead of the prebuild DockerHub image'
            );
        $this->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings.
You will be asked to enter production domains, choose PHP version and web root folder.
You will be asked to add more environments for staging/text/development/etc. environments with the same or new domains.
If you made a mistype in the PHP version or domain names - re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>php %command.full_name%</info>

Example usage with PHP version, MySQL container and with domains, without questions when possible
(non-interactive mode) and without adding more environments:

    <info>php %command.full_name% --domains='example.com www.example.com' --php=7.3 --mysql-container=mysql57 -n</info>

Magento 1 example with custom web root:

    <info>php %command.full_name% --web-root='/' --domains='example.com www.example.com' --php=5.6 --mysql-container=mysql56</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function getQuestions(): array
    {
        return [
            ProjectMountRoot::OPTION_NAME,
            WebRoot::OPTION_NAME,
            PhpVersion::OPTION_NAME,
            ComposerVersion::OPTION_NAME,
            MysqlContainer::OPTION_NAME,
            Domains::OPTION_NAME
        ];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = self::SUCCESS;
        $cwd = getcwd();

        try {
            // Validate `--execution-environment` option if passed
            if (
                ($executionEnvironment = $input->getOption(self::OPTION_EXECUTION_ENVIRONMENT))
                && !in_array(
                    $executionEnvironment,
                    [Env::EXECUTION_ENVIRONMENT_DEVELOPMENT, Env::EXECUTION_ENVIRONMENT_PRODUCTION],
                    true
                )
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid \'%s\' option value. Allowed values: %s, %s',
                    self::OPTION_EXECUTION_ENVIRONMENT,
                    Env::EXECUTION_ENVIRONMENT_DEVELOPMENT,
                    Env::EXECUTION_ENVIRONMENT_PRODUCTION
                ));
            }

            // 1. Use current folder as a project root, update permissions (in case there is something owned by root)
            // @TODO: notify about the project root, do not run directly in the system dirs
            // ask to agree if run not in the PROJECTS_ROT_DIR
            if ($projectRoot = trim((string) $input->getOption(self::OPTION_PATH))) {
                $projectRoot = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR;
                chdir($projectRoot);
            }

            $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
            $projectsRootDirEnvVariable = $this->env->getProjectsRootDir();

            if ($projectRoot === $projectsRootDirEnvVariable) {
                throw new \RuntimeException(<<<'TEXT'
                This folder is defined as PROJECTS_ROOT_DIR in your environment.
                Check you `~/.bash_aliases` file for this variables:
                $ cat ~/.bash_aliases | grep 'export PROJECTS_ROOT_DIR'
                TEXT);
            }

            if (strpos($projectRoot, $projectsRootDirEnvVariable) !== 0) {
                $questionText = <<<TEXT
                Project root dir (PROJECTS_ROOT_DIR env variable) is: <fg=blue>$projectsRootDirEnvVariable</fg=blue>
                Current project's directory is: <fg=blue>$projectRoot</fg=blue>
                Are you sure you want to continue Dockerization <fg=blue>(N/y)</fg=blue>? 
                TEXT;
                $question = new ConfirmationQuestion($questionText, false);

                if (!$this->getHelper('question')->ask($input, $output, $question)) {
                    return self::FAILURE;
                }
            }

            $currentUser = get_current_user();
            $userGroup = filegroup($this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE));
            $this->shell->sudoPassthru("chown -R $currentUser:$userGroup $projectRoot");
            $projectMountRoot = $this->ask(ProjectMountRoot::OPTION_NAME, $input, $output);
            $this->shell->passthru("mkdir -p $projectMountRoot/var/log");

            if (!is_dir("$projectMountRoot/var/log")) {
                $output->writeln(<<<'EOF'
                <error>Can not create log dir <fg=blue>var/log/</fg=blue>. Container may not run properly because
                the web server is not able to write logs!</error>
                EOF);
            }

            // 2. Document root
            $webRoot = $this->ask(WebRoot::OPTION_NAME, $input, $output);
            // `ltrim()` id $webRoot === '/'
            $hostWebRoot = $projectRoot . $projectMountRoot . DIRECTORY_SEPARATOR . ltrim($webRoot, '/');

            if (!is_dir($hostWebRoot)) {
                throw new \InvalidArgumentException(
                    "Web root directory '$hostWebRoot' does not exist in the mount root!"
                );
            }

            $output->writeln("<info>Web root folder: </info><fg=blue>$hostWebRoot</fg=blue>\n");

            // 3. Get domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask(Domains::OPTION_NAME, $input, $output);

            // 2. Get PHP version, copy files for docker-compose
            /** @var PhpVersion $phpVersionQuestion */
            $phpVersion = $this->ask(PhpVersion::OPTION_NAME, $input, $output);
            $composerVersion = $this->ask(ComposerVersion::OPTION_NAME, $input, $output);
            $projectTemplateFiles = $this->filesystem->getProjectTemplateFiles();
            $projectTemplateDir = $this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE);

            foreach ($projectTemplateFiles as $file) {
                @unlink($file);
                $templateFile = $projectTemplateDir . $file;

                if (strpos($file, DIRECTORY_SEPARATOR) !== false) {
                    $this->shell->passthru('mkdir -p ' . dirname($projectRoot . $file));
                }

                $this->shell->passthru("cp -r $templateFile $file");
            }

            // 4. Get MySQL container to connect link composition
            $mysqlContainer = $this->ask(MysqlContainer::OPTION_NAME, $input, $output);

            // 5. Generate SSL certificates
            $sslCertificateFiles = $this->sslCertificate->generateSslCertificates($domains);

            // Show full command for reference and for the future use
            $dockerizationParameters = [
                Domains::OPTION_NAME         => implode(' ', $domains),
                ProjectMountRoot::OPTION_NAME     => $projectMountRoot,
                WebRoot::OPTION_NAME         => $webRoot,
                PhpVersion::OPTION_NAME      => $phpVersion,
                ComposerVersion::OPTION_NAME => $composerVersion,
                MysqlContainer::OPTION_NAME  => $mysqlContainer
            ];
            array_walk($dockerizationParameters, function(&$value, $key) {
                $value = "--$key='$value'";
            });
            $output->writeln(sprintf(
                "<info>Full dockerization command: </info><fg=blue>cd %s ; %s %s %s %s</fg=blue>\n",
                $projectRoot,
                PHP_BINARY,
                $this->filesystem->getDockerizerExecutable(),
                $this->getName(),
                implode(' ', $dockerizationParameters)
            ));

            // 6. Update files
            $this->fileProcessor->processDockerCompose(
                $projectTemplateFiles,
                $domains,
                $domains[0],
                $mysqlContainer,
                $phpVersion,
                $composerVersion,
                $projectMountRoot,
                $input->getOption(self::OPTION_ELASTICSEARCH),
                $executionEnvironment
            );
            $this->fileProcessor->processVirtualHostConf(
                $projectTemplateFiles,
                $domains,
                $sslCertificateFiles,
                $webRoot
            );

            if ($webRoot === '/') {
                // .htaccess won't exist on first dockerization while installing clean Magento instance
                $this->fileProcessor->processHtaccess($projectTemplateFiles, false);
            }

            $this->fileProcessor->processTraefikRules($sslCertificateFiles);
            $this->fileProcessor->processHosts($domains);
            // @TODO: return container names after dockerization, so that we can turn them off
        } catch (\Exception $e) {
            $exitCode = self::FAILURE;
            $output->writeln("<error>{$e->getMessage()}</error>");
        } finally {
            chdir($cwd);
        }

        return $exitCode;
    }
}
