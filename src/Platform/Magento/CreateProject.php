<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Php;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Install Magento
 *
 * Composition template and services myst be configured in the respective command (or web app if we ever get it)
 */
class CreateProject
{
    private const MAGENTO_REPOSITORY = 'https://%s:%s@repo.magento.com/';

    // @TODO: add file hash validation
    private const COMPOSER_1_DOWNLOAD_URL = 'https://getcomposer.org/download/1.10.26/composer.phar';

    /**
     * Magento composer plugins that must be allowed if we do not want to answer Composer questions
     */
    private const ALLOWED_PLUGINS = [
        'hirak/prestissimo',
        'laminas/laminas-dependency-plugin',
        'dealerdirect/phpcodesniffer-composer-installer',
        'magento/composer-dependency-version-audit-plugin',
        'magento/composer-root-update-plugin',
        'magento/inventory-composer-installer',
        'magento/magento-composer-installer'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpContainer
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpContainer,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Platform\Magento $magento
    ) {
    }

    /**
     * @param string $mainDomain
     * @return string
     */
    public function getProjectRoot(string $mainDomain): string
    {
        $dir = $this->env->getProjectsRootDir() . $mainDomain;
        $this->filesystem->mkdir($dir);

        return realpath($dir) . DIRECTORY_SEPARATOR;
    }

    /**
     * Install Magento from creating a directory to running application
     *
     * @param OutputInterface $output
     * @param string $magentoVersion
     * @param string[] $domains
     * @param bool $force
     * @return void
     * @throws \Exception
     */
    public function createProject(OutputInterface $output, string $magentoVersion, array $domains, bool $force): void
    {
        // === Configure required directories ===
        $mainDomain = $domains[0];
        $projectRoot = $this->getProjectRoot($mainDomain);
        // Do this here in order to be sure we have these parameters
        $this->getAuthJson();

        // === Check if installation directory is empty ===
        $this->validateCanInstallHere($projectRoot, $force);
        $output->writeln('Cleaning up the project directory...');
        $output->writeln('This action can\'t be undone!');
        chdir($projectRoot);
        $this->cleanup($projectRoot);
        $this->filesystem->mkdir($projectRoot);
        // getcwd() return false after cleanup, because original dir is deleted
        chdir($projectRoot);

        // === 1. Dockerize ===
        $output->writeln('Generating composition files and running it...');
        /*
        $webRoot = (string) $this->composition->getParameterValue('web_root');
        // Web root is not available on the first dockerization before actually installing Magento - create it
        $this->filesystem->mkdir($projectRoot . ltrim($webRoot, '\\/'));
        */
        // @TODO: must be done while dumping composition and processing virtual hosts file
        $this->filesystem->mkdir($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');
        $modificationContext = $this->composition->dump($output, $projectRoot, false);
        $dockerComposeDir = $modificationContext->getDockerComposeDir();
        $dockerCompose = $this->dockerCompose->initialize($dockerComposeDir);
        // just in case previous setup was not successful
        $dockerCompose->down();
        $output->writeln('Running composition. It may take time to download Docker images...');
        $dockerCompose->up(true, true);
        $phpContainerName = $dockerCompose->getServiceContainerName(AppContainers::PHP_SERVICE);
        $phpContainer = $this->phpContainer->initialize($phpContainerName);

        // For testing with composer packages cache
        //$this->shell->run(
        //    "docker exec -u root $phpContainerName sh -c 'chown -R docker:docker /home/docker/.composer'"
        //);
        $output->writeln('Setting composer to trust Magento composer plugins...');

        foreach (self::ALLOWED_PLUGINS as $plugin) {
            // Redirect output to /dev/null to suppress errors from the early Composer versions
            $phpContainer->run(
                "composer config --global --no-interaction allow-plugins.$plugin true 1>/dev/null 2>/dev/null"
            );
        }

        // === 2. Create Magento project ===
        $process = $phpContainer->mustRun('composer -V', Shell::EXECUTION_TIMEOUT_SHORT, false);
        $composerMeta = trim($process->getOutput(), '');
        $composerVersion = (int) ((string) preg_replace('/\D/', '', $composerMeta))[0] === 1 ? 1 : 2;
        $configuredAuthJson = $this->getAuthJson($composerVersion);

        // Must write project files to /var/www/html/project/ and move files to the WORKDIR
        // This is required because `.dockerizer` dir is present and can be deleted due to mounted files there
        $magentoRepositoryUrl = sprintf(
            self::MAGENTO_REPOSITORY,
            $configuredAuthJson['http-basic']['repo.magento.com']['username'],
            $configuredAuthJson['http-basic']['repo.magento.com']['password']
        );

        // A workaround so that we do not have too high memory limit in PHP containers with old PHP versions
        if (
            Comparator::lessThan($magentoVersion, '2.2.0')
            && Comparator::lessThan($phpContainer->getPhpVersion(), '7.1')
        ) {
            $composerPharUrl = self::COMPOSER_1_DOWNLOAD_URL;
            $phpContainer->mustRun("curl $composerPharUrl --output composer.phar 2>/dev/null");
            // Magento 2.1.18 fails with:
            // Fatal error: Allowed memory size of 4294967296 bytes exhausted (tried to allocate 32 bytes) in phar:///var/www/html/composer.phar/src/Composer/DependencyResolver/RuleWatchNode.php on line 40
            // Need to reassemble images and check again. Maybe later Composer 1 versions have some memory usage optimizations.
            $composer = 'php -d memory_limit=6G composer.phar';
        } else {
            $composer = 'composer';
        }

        $magentoCreateProject = sprintf(
            '%s create-project %s --repository=%s %s=%s /var/www/html/project/',
            $composer,
            $output->isQuiet() ? '-q' : '',
            $magentoRepositoryUrl,
            Magento::MAGENTO_CE_PROJECT,
            $magentoVersion
        );
        $output->writeln('Calling "composer create-project" to get project files...');

        // Just `run` (not `mustRun`), because composer returns warnings to the error stream. We will anyway fail later.
        // Though, we must try once more on error, because sometimes it fails due to network issues.
        // This happens more often then we would like to.
        $phpContainer->run($magentoCreateProject, Shell::EXECUTION_TIMEOUT_LONG, !$output->isQuiet());

        try {
            $this->magento->validateIsMagento($phpContainer, 'project/');
        } catch (\RuntimeException) {
            $output->writeln('Failed to run "composer create-project". Trying once more...');
            $phpContainer->run('rm -rf /var/www/html/project/');
            $createProjectProcess = $phpContainer->run(
                $magentoCreateProject,
                Shell::EXECUTION_TIMEOUT_LONG,
                !$output->isQuiet()
            );

            try {
                $this->magento->validateIsMagento($phpContainer, 'project/');
            } catch (\RuntimeException $e) {
                if (!$createProjectProcess->isSuccessful()) {
                    throw new ProcessFailedException($createProjectProcess);
                }

                throw $e;
            }
        }

        if (
            Comparator::lessThan($magentoVersion, '2.2.0')
            && Comparator::lessThan($phpContainer->getPhpVersion(), '7.1')
        ) {
            $phpContainer->mustRun('rm composer.phar');
        }

        // Move files to the WORKDIR. Note that `/var/www/html/var/` is not empty, so `mv` can't move its content
        $phpContainer->mustRun('cp -r /var/www/html/project/var/ /var/www/html/');
        $phpContainer->mustRun('rm -rf /var/www/html/project/var/');
        $phpContainer->mustRun(
            'sh -c \'ls -A -1 /var/www/html/project/ | xargs -I {} mv -f /var/www/html/project/{} /var/www/html/\''
        );
        $phpContainer->mustRun('rmdir /var/www/html/project/');

        // === 3. Initialize Git repository ===
        $output->writeln('Initializing repository with Magento 2 files...');
        // Hotfix for Magento 2.4.1
        // @TODO: install 2.4.1 and test this, check patches
        if (!$phpContainer->isFile('.gitignore')) {
            $this->addGitignoreFrom240($phpContainer);
        }

        $this->shell->mustRun('git init');
        $this->shell->mustRun('git config core.fileMode false');

        // Set username if not is set globally
        if (!$this->shell->run('git config user.name')->isSuccessful()) {
            $this->shell->mustRun('git config user.name Dockerizer');
            $output->writeln('<info>Set git user.name for this repository!</info>');
        }

        // Set user email if not is set globally
        if (!$this->shell->run('git config user.email')->isSuccessful()) {
            $this->shell->mustRun('git config user.email email@example.com');
            $this->shell->mustRun('git config commit.gpgSign false');
            $output->writeln('<info>Set git user.email for this repository!</info>');
        }

        $this->shell->mustRun('git add -A');
        $this->shell->mustRun('git commit -m "Initial commit" -q');

        $this->shell->mustRun('mkdir -p ./var/log/');
        $this->shell->mustRun('touch ./var/log/.gitkeep');
        $this->shell->mustRun('echo \'!/var/log/\' | tee -a .gitignore');
        $this->shell->mustRun('echo \'/var/log/*\' | tee -a .gitignore');
        $this->shell->mustRun('echo \'!/var/log/.gitkeep\' | tee -a .gitignore');

        $magentoAuthJson = $this->generateAuthJson($phpContainer, $composerVersion, $output);
        $phpContainer->filePutContents('auth.json', $magentoAuthJson);
    }

    /**
     * @param string $projectRoot
     * @param bool $force
     * @return void
     */
    public function validateCanInstallHere(string $projectRoot, bool $force): void
    {
        // Prepare installation directory
        if (
            !$force
            && !$this->filesystem->isEmptyDir($projectRoot)
        ) {
            throw new InstallationDirectoryNotEmptyException(<<<EOF
            Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
            Stop all containers (if any), remove the directory and re-run setup.
            You can also use '-f' option to force install Magento with this domain.
            EOF);
        }
    }

    /**
     * @param string $projectRoot
     * @return void
     */
    public function cleanup(string $projectRoot): void
    {
        try {
            foreach ($this->compositionCollection->getList($projectRoot) as $dockerCompose) {
                $dockerCompose->down();
            }

            $this->filesystem->remove($projectRoot);
        } catch (\Exception $e) {
            throw new CleanupException($e->getMessage());
        }
    }

    /**
     * @param Php $phpContainer
     * @param int $composerVersion
     * @param OutputInterface $output
     * @return string
     * @throws \JsonException
     */
    private function generateAuthJson(Php $phpContainer, int $composerVersion, OutputInterface $output): string
    {
        $authJson = $this->getAuthJson($composerVersion);
        // Skip everything that is not needed for Magento
        $magentoAuthJson = [
            'http-basic' => [
                'repo.magento.com' => [
                    'username' => $authJson['http-basic']['repo.magento.com']['username'],
                    'password' => $authJson['http-basic']['repo.magento.com']['password']
                ]
            ]
        ];

        $composeLock = (array) json_decode(
            $phpContainer->fileGetContents('composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $composerPackageMeta = array_filter(
            (array) $composeLock['packages'],
            static fn (mixed $item): bool => is_array($item) && $item['name'] === 'composer/composer'
        );
        $composerVersion = (string) array_values($composerPackageMeta)[0]['version'];

        // https://support.magento.com/hc/en-us/articles/4402562382221-Github-token-issue-and-Composer-key-procedures
        // @TODO: 2.3.7 > 1.10.20; check with Magento 2.3.7
        if (Comparator::greaterThanOrEqualTo($composerVersion, '1.10.21')) {
            $magentoAuthJson['github-oauth']['github.com'] = $authJson['github-oauth']['github.com'];
        } else {
            $output->writeln(
                'Skip adding github.com oAuth token, because new tokens are not supported by Composer prior to 1.10.21'
            );
        }

        return json_encode($magentoAuthJson, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param int $composerVersion
     * @return non-empty-array<string, array>
     * @throws \JsonException
     */
    private function getAuthJson(int $composerVersion = 1): array
    {
        $authJson = $this->filesystem->getAuthJson();

        if (
            !isset(
                $authJson['http-basic']['repo.magento.com']['username'],
                $authJson['http-basic']['repo.magento.com']['password'],
                $authJson['github-oauth']['github.com'],
            )
        ) {
            throw new \RuntimeException(
                'The file "auth.json" does not contain "username" or "password" for "repo.magento.com",' .
                ' and a GitHub key!'
            );
        }

        // if composer version === 1 - remove `ghp_` from the key
        if ($composerVersion === 1) {
            $authJson['github-oauth']['github.com'] = explode('_', $authJson['github-oauth']['github.com'])[1];
        }

        return $authJson;
    }

    /**
     * @param Php $phpContainer
     * @return void
     */
    private function addGitignoreFrom240(Php $phpContainer): void
    {
        $phpContainer->filePutContents(
            '.gitignore',
            <<<GITIGNORE
            /.buildpath
            /.cache
            /.metadata
            /.project
            /.settings
            /.vscode
            atlassian*
            /nbproject
            /robots.txt
            /pub/robots.txt
            /sitemap
            /sitemap.xml
            /pub/sitemap
            /pub/sitemap.xml
            /.idea
            /.gitattributes
            /app/config_sandbox
            /app/etc/config.php
            /app/etc/env.php
            /app/code/Magento/TestModule*
            /lib/internal/flex/uploader/.actionScriptProperties
            /lib/internal/flex/uploader/.flexProperties
            /lib/internal/flex/uploader/.project
            /lib/internal/flex/uploader/.settings
            /lib/internal/flex/varien/.actionScriptProperties
            /lib/internal/flex/varien/.flexLibProperties
            /lib/internal/flex/varien/.project
            /lib/internal/flex/varien/.settings
            /node_modules
            /.grunt
            /Gruntfile.js
            /package.json
            /.php_cs
            /.php_cs.cache
            /grunt-config.json
            /pub/media/*.*
            !/pub/media/.htaccess
            /pub/media/attribute/*
            !/pub/media/attribute/.htaccess
            /pub/media/analytics/*
            /pub/media/catalog/*
            !/pub/media/catalog/.htaccess
            /pub/media/customer/*
            !/pub/media/customer/.htaccess
            /pub/media/downloadable/*
            !/pub/media/downloadable/.htaccess
            /pub/media/favicon/*
            /pub/media/import/*
            !/pub/media/import/.htaccess
            /pub/media/logo/*
            /pub/media/custom_options/*
            !/pub/media/custom_options/.htaccess
            /pub/media/theme/*
            /pub/media/theme_customization/*
            !/pub/media/theme_customization/.htaccess
            /pub/media/wysiwyg/*
            !/pub/media/wysiwyg/.htaccess
            /pub/media/tmp/*
            !/pub/media/tmp/.htaccess
            /pub/media/captcha/*
            /pub/static/*
            !/pub/static/.htaccess

            /var/*
            !/var/.htaccess
            /vendor/*
            !/vendor/.htaccess
            /generated/*
            !/generated/.htaccess
            .DS_Store

            GITIGNORE
        );
    }
}
