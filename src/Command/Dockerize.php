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
     * @var \App\Service\FileProcessor
     */
    private $fileProcessor;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Filesystem $filesystem
     * @param \App\Service\FileProcessor $fileProcessor
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        $name = null
    ) {
        $this->filesystem = $filesystem;
        parent::__construct($env, $shell, $questionPool, $name);
        $this->fileProcessor = $fileProcessor;
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

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name%</info>

Example usage with PHP version, MySQL container and with domains, without questions when possible
(non-interactive mode) and without adding more environments:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=7.3 --mysql-container=mysql57 --domains='example.com www.example.com' -n</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=5.6 --mysql-container=mysql56 --domains='example.com www.example.com' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
EOF);

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
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = 0;
        $cwd = getcwd();

        try {
            // 0. Use current folder as a project root, update permissions (in case there is something owned by root)
            if ($projectRoot = trim((string) $input->getOption(self::OPTION_PATH))) {
                $projectRoot = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR;
                chdir($projectRoot);
            }

            $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
            $currentUser = get_current_user();
            $userGroup = filegroup($this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE));
            $this->shell->sudoPassthru("chown -R $currentUser:$userGroup ./");
            $this->shell->passthru('mkdir -p var/log');

            if (!is_dir('var/log')) {
                $output->writeln(<<<'EOF'
                <error>Can not create log dir <fg=blue>var/log/</fg=blue>. Container may not run properly because
                the web server is not able to write logs!</error>
                EOF);
            }

            // 1. Get domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask(Domains::QUESTION, $input, $output);

            // 2. Get PHP version, copy files for docker-compose
            /** @var PhpVersion $phpVersionQuestion */
            $phpVersion = $this->ask(PhpVersion::QUESTION, $input, $output);
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

            $phpDockerfilesDir = $this->filesystem->getDirPath(Filesystem::DIR_PHP_DOCKERFILES);
            // We will have multiple Dockerfiles in the future....
            $this->shell->passthru(<<<BASH
                rm ./docker/Dockerfile
                cp {$phpDockerfilesDir}{$phpVersion}/Dockerfile ./docker/Dockerfile
            BASH);

            // 3. Get MySQL container to connect link composition
            $mysqlContainer = $this->ask(MysqlContainer::QUESTION, $input, $output);

            // 4. Generate SSL certificates
            $sslCertificateFiles = $this->filesystem->generateSslCertificates($domains);

            // 5. Document root
            if (!$webRoot = $input->getOption(self::OPTION_WEB_ROOT)) {
                $question = new Question(<<<'EOF'
                <info>Enter web root relative to the current folder. Default web root is <fg=blue>pub/</fg=blue>
                Leave empty to use default, enter new web root or enter <fg=blue>/</fg=blue> for current folder: </info>
                EOF);

                $webRoot = trim((string) $this->getHelper('question')->ask($input, $output, $question));

                if (!$webRoot) {
                    $webRoot = 'pub/';
                } elseif ($webRoot === '/') {
                    $webRoot = '';
                } else {
                    $webRoot = trim($webRoot, '/') . '/';
                }
            }

            if (!is_dir($projectRoot . $webRoot)) {
                throw new \InvalidArgumentException("Web root directory '$webRoot' does not exist");
            }

            $output->writeln("<info>Web root folder: </info><fg=blue>{$projectRoot}{$webRoot}</fg=blue>\n");

            // 6. Update files
            $this->fileProcessor->processDockerComposeFiles(
                $projectTemplateFiles,
                [
                    'example.com,www.example.com,example-2.com,www.example-2.com',
                    'example.com www.example.com example-2.com www.example-2.com',
                    'example.com'
                ],
                $domains,
                $domains[0],
                $mysqlContainer
            );
            $this->fileProcessor->processVirtualHostConf(
                $projectTemplateFiles,
                $domains,
                $sslCertificateFiles,
                $webRoot
            );

            // .htaccess won't exist on first dockerization while installing clean Magento instance
            $this->fileProcessor->processHtaccess($projectTemplateFiles, false);
            $this->fileProcessor->processTraefikRules($sslCertificateFiles);
            $this->fileProcessor->processHosts($domains);
        } catch (\Exception $e) {
            $exitCode = 1;
            $output->writeln("<error>{$e->getMessage()}</error>");
        } finally {
            chdir($cwd);
        }

        return $exitCode;
    }
}
