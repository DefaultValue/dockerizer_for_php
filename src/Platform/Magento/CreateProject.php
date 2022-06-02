<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Platform\Magento;

use Composer\Semver\Comparator;
use DefaultValue\Dockerizer\Docker\Compose\CompositionFilesNotFoundException;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Platform\Magento\Exception\CleanupException;
use DefaultValue\Dockerizer\Platform\Magento\Exception\InstallationDirectoryNotEmptyException;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Output\OutputInterface;

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
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpContainer
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Php $phpContainer,
        private \DefaultValue\Dockerizer\Shell\Shell $shell
    ) {
    }

    /**
     * @param string $dir
     * @return string
     */
    public function getProjectRoot(string $dir): string
    {
        return $this->filesystem->getDirPath($dir);
    }

    /**
     * Install Magento from creating a directory to running application
     *
     * @param OutputInterface $output
     * @param string $magentoVersion
     * @param array $domains
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

        // Prepare installation directory
        if (!$this->filesystem->isEmptyDir($projectRoot)) {
            if ($force) {
                $output->writeln('Cleaning up the project directory...');
                $this->cleanUp($projectRoot);
                $this->filesystem->getDirPath($projectRoot);
            } else {
                // Unset variable so that project files are not removed in this particular case
                unset($mainDomain);
                throw new InstallationDirectoryNotEmptyException(<<<EOF
                Directory "$projectRoot" already exists and may not be empty. Can't deploy here.
                Stop all containers (if any), remove the folder and re-run setup.
                You can also use '-f' option to force install Magento with this domain.
                EOF);
            }
        }

        // getcwd() return false after cleanup, because original dir is deleted
        chdir($projectRoot);

        // === 1. Dockerize ===
        $output->writeln('Generating composition files and running it...');
        $webRoot = $this->composition->getParameterValue('web_root');
        // Web root is not available on the first dockerization before actually installing Magento - create it
        $this->filesystem->getDirPath($projectRoot . ltrim($webRoot, '\\/'));
        // @TODO: must be done while dumping composition and processing virtual hosts file
        $this->filesystem->getDirPath($projectRoot . 'var' . DIRECTORY_SEPARATOR . 'log');
        $modificationContext = $this->composition->dump($output, $projectRoot, false);
        $dockerComposeDir = $modificationContext->getDockerComposeDir();
        $dockerCompose = $this->dockerCompose->initialize($dockerComposeDir);
        // just in case previous setup was not successful
        $dockerCompose->down();
        $dockerCompose->up(true, true);
        $phpContainerName = $dockerCompose->getServiceContainerName(Magento::PHP_SERVICE);
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
        $composerVersion = (int) preg_replace('/\D/', '', $composerMeta)[0] === 1 ? 1 : 2;
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
            $composer = 'php -d memory_limit=4G composer.phar';
        } else {
            $composer = 'composer';
        }

        $magentoCreateProject = sprintf(
            '%s create-project %s --repository=%s %s=%s /var/www/html/project/',
            $composer,
            $output->isQuiet() ? '-q' : '',
            $magentoRepositoryUrl,
            Magento::MAGENTO_CE_PACKAGE,
            $magentoVersion
        );
        $output->writeln('Calling "composer create-project" to get project files...');

        // Just run, because composer returns warnings to the error stream. We will anyway fail later
        $phpContainer->run($magentoCreateProject, Shell::EXECUTION_TIMEOUT_LONG);

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
        if (!file_exists("$projectRoot.gitignore")) {
            $this->addGitignoreFrom240($projectRoot);
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
            $output->writeln('<info>Set git user.email for this repository!</info>');
        }

        $this->shell->mustRun('git add -A');
        $this->shell->mustRun('git commit -m "Initial commit" -q');

        $this->shell->mustRun('mkdir -p ./var/log/');
        $this->shell->mustRun('touch ./var/log/.gitkeep');
        $this->shell->mustRun('echo \'!/var/log/\' | tee -a .gitignore');
        $this->shell->mustRun('echo \'/var/log/*\' | tee -a .gitignore');
        $this->shell->mustRun('echo \'!/var/log/.gitkeep\' | tee -a .gitignore');

        $magentoAuthJson = $this->generateAutoJson($projectRoot, $composerVersion, $output);
        $this->filesystem->filePutContents($projectRoot . 'auth.json', $magentoAuthJson);
    }

    /**
     * @param string $projectRoot
     * @return void
     */
    public function cleanUp(string $projectRoot): void
    {
        try {
            foreach ($this->composition->getDockerComposeCollection($projectRoot) as $dockerCompose) {
                $dockerCompose->down();
            }

            $this->filesystem->remove([$projectRoot]);
        } catch (\Exception $e) {
            throw new CleanupException($e->getMessage());
        }
    }

    /**
     * @param string $projectRoot
     * @param int $composerVersion
     * @param OutputInterface $output
     * @return string
     * @throws \JsonException
     */
    private function generateAutoJson(string $projectRoot, int $composerVersion, OutputInterface $output): string
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

        $composeLock = json_decode(
            $this->filesystem->fileGetContents($projectRoot . 'composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $composerPackageMeta = array_filter(
            $composeLock['packages'],
            static fn ($item) => $item['name'] === 'composer/composer'
        );
        $composerVersion = array_values($composerPackageMeta)[0]['version'];

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
     * @return array
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
     * @param string $projectRoot
     */
    private function addGitignoreFrom240(string $projectRoot): void
    {
        file_put_contents(
            "$projectRoot.gitignore",
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
