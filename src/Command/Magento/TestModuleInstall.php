<?php

declare(strict_types=1);

namespace App\Command\Magento;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestModuleInstall extends \Symfony\Component\Console\Command\Command
{
    private const OPTION_FOLDER = 'folder';

    private const OPTION_TOGETHER = 'together';

    /**
     * Cleanup next directories
     * @var array
     */
    private $magentoDirectoriesAndFilesToClean = [
        'app/etc/config.php',
        'app/etc/env.php',
        'app/code/*',
        'generated/code/*',
        'generated/metadata/*',
        'var/di/*',
        'var/generation/*',
        'var/cache/*',
        'var/page_cache/*',
        'var/view_preprocessed/*',
        'pub/static/frontend/*',
        'pub/static/adminhtml/*'
    ];

    /**
     * @var \App\Service\MagentoInstaller $magentoInstaller
     */
    private $magentoInstaller;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;
    /**
     * @var \App\Service\Database
     */
    private $database;

    /**
     * ModuleDeployAfterMagentoCommand constructor.
     * @param \App\Service\MagentoInstaller $magentoInstaller
     * @param \App\Service\Shell $shell
     * @param \App\Service\Database $database
     * @param ?string $name
     */
    public function __construct(
        \App\Service\MagentoInstaller $magentoInstaller,
        \App\Service\Shell $shell,
        \App\Service\Database $database,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->magentoInstaller = $magentoInstaller;
        $this->shell = $shell;
        $this->database = $database;
    }

    /**
     * @return void
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('magento:test-module-install')
            ->setDescription(
                '<info>Re-install Magento 2 application, copy modules from the directory, run Magento deploy</info>'
            )->addOption(
                self::OPTION_TOGETHER,
                't',
                InputOption::VALUE_NONE,
                'Run Magento setup with required modules'
            )->addArgument(
                self::OPTION_FOLDER,
                InputArgument::REQUIRED,
                'Modules folder directory'
            )->setHelp(<<<'EOF'
The <info>%command.name%</info> command allows testing modules installation on the existing Magento 2 instance.
Use the command in the Magento root folder.

Usage:

1) Common flow - install Sample Data, reinstall Magento, install module:

    <info>php %command.full_name% /folder/with/modules</info>

2) CI/CD flow - install Sample Data, copy module(s) inside Magento, reinstall Magento:
To copy modules prior to installing Magento 2 use the option <fg=yellow>--together</fg=yellow> or short <fg=yellow>-t</fg=yellow>:

    <info>php %command.full_name% /folder/with/modules --together</info>

EOF);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);

        $mainDomain = basename(getcwd());
        $modulesFolder = $input->getArgument(self::OPTION_FOLDER);

        $together = $input->getOption(self::OPTION_TOGETHER);

        try {
            if (file_exists('./composer.json')) {
                $magentoComposerFile = json_decode(file_get_contents('./composer.json'));

                if (!is_dir('./app') || strpos($magentoComposerFile->name, 'magento/') === false) {
                    throw new \RuntimeException('<error>Seems we\'re not inside the Magento project</error>');
                }
            } else {
                throw new \RuntimeException('<error>Composer.json not found</error>');
            }
        } catch (\RuntimeException $exception) {
            $output->writeln($exception->getMessage());
            return 1;
        }

        if (!is_dir($modulesFolder)) {
            $output->writeln('<error>Modules directory does exist.</error>');
            return 1;
        }

        $modules = $this->detectModules($modulesFolder);

        if (empty($modules)) {
            $output->writeln('<error>Modules directory is empty.</error>');
            return 1;
        }

        $output->writeln('<info>Modules list:</info>');

        foreach ($modules as $vendor => $modulesList) {
            foreach ($modulesList as $moduleName => $moduleInfo) {
                $output->writeln("- <info>{$vendor}_{$moduleName}</info>");
            }
        }

        # Stage 0: clean Magento 2, install Magento application, handle together attribute
        $output->writeln('<info>Cleanup Magento 2 application...</info>');

        $filesAndFolderToRemove = implode(' ', $this->magentoDirectoriesAndFilesToClean);
        $this->shell->dockerExec("sh -c \"rm -rf {$filesAndFolderToRemove}\"", $mainDomain);

        if ($together) {
            $output->writeln('<info>Copying modules to run installation together with the Magento...</info>');
            $this->copyModules($modules, $mainDomain);
        }

        $output->writeln('<info>Reinstalling Magento 2 application...</info>');

        $magentoVersion = $magentoComposerFile->require->{'magento/product-community-edition'};
        $elasticsearchHost = version_compare($magentoVersion, '2.4.0', 'lt') ? '' : 'elasticsearch';

        $phpVersion = substr($this->shell->exec("docker exec $mainDomain php -r 'echo phpversion();'")[0], 0, 3);
        // Really poor way to do this
        $mysqlContainer = $this->shell->exec('docker-compose config | grep :mysql')[0];
        $mysqlContainer = trim(str_replace(' - ', '', explode(':', $mysqlContainer)[0]));
        $this->database->connect($mysqlContainer);
        $this->magentoInstaller->refreshDbAndInstall(
            $mainDomain,
            $magentoVersion === '2.4.0' && $phpVersion === '7.3',
            $elasticsearchHost
        );
        $this->magentoInstaller->updateMagentoConfig($mainDomain);

        # Stage 1: deploy Sample Data if required, run setup upgrade
        if (!file_exists('.' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.sample-data-state.flag')) {
            $output->writeln('<info>Deploy Sample Data...</info>');
            $this->shell->dockerExec('php bin/magento sampledata:deploy', $mainDomain);

            if ($together) {
                $this->magentoInstaller->refreshDbAndInstall(
                    $mainDomain,
                    $magentoVersion === '2.4.0' && $phpVersion === '7.3',
                    $elasticsearchHost
                );
                $this->magentoInstaller->updateMagentoConfig($mainDomain);
            }
        }

        //TODO if sample data deployed in together mode skip this
        $this->shell->dockerExec('php bin/magento setup:upgrade', $mainDomain);

        # Stage 2: run reindex, switch to the production mode
        $output->writeln('<info>Running reindex, switching to the production mode...</info>');

        $this->shell->dockerExec('php bin/magento indexer:reindex', $mainDomain);
        $this->shell->dockerExec('php bin/magento deploy:mode:set production', $mainDomain);

        # Stage 3: copy modules and run setup:upgrade if it has not been done before, commit changes
        if (!$together) {
            $output->writeln('<info>Copying modules...</info>');
            $this->copyModules($modules, $mainDomain);

            $this->shell->dockerExec('php bin/magento setup:upgrade', $mainDomain);
        }

        $output->writeln('<info>Commit changes...</info>');

        $this->shell->passthru(<<<BASH
            git config core.fileMode false
            git add ./app/code/*
            git add -u ./app/code/*
            git commit -m "New build"
BASH
        , true);

        # Stage 4: switch magento 2 to the production mode, run final reindex
        $output->writeln('<info>Final reindex...</info>');

        $this->shell->dockerExec('php bin/magento deploy:mode:set production', $mainDomain);
        $this->shell->dockerExec('php bin/magento indexer:reindex', $mainDomain);

        $endTime = microtime(true);
        $minutes = floor(($endTime - $startTime) / 60);
        $seconds = round($endTime - $startTime - $minutes * 60, 2);
        $output->writeln("<info>Completed in {$minutes} minutes {$seconds} seconds!</info>");

        return 0;
    }

    /**
     * @param $baseDirectory
     * @param array $modules
     * @return array
     */
    private function detectModules($baseDirectory, $modules = []): array
    {
        foreach (array_diff(scandir($baseDirectory), array('..', '.', '.git')) as $directoryChild) {
            $detectedDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $directoryChild;

            if (
                file_exists($detectedDirectory . DIRECTORY_SEPARATOR . 'registration.php') &&
                file_exists($detectedDirectory . DIRECTORY_SEPARATOR . 'etc/module.xml')
            ) {
                $moduleName =
                    simplexml_load_file("{$detectedDirectory}/etc/module.xml")->module->attributes()->name;

                if ($moduleName) {
                    $explodedModuleName = explode('_', (string) $moduleName);
                    $modules[$explodedModuleName[0]][$explodedModuleName[1]] = [
                        'path' => $detectedDirectory
                    ];
                }

                continue;
            }

            if (is_dir($detectedDirectory)) {
                $modules = $this->detectModules($detectedDirectory, $modules);
            }
        }

        return $modules;
    }

    /**
     * @param array $modules
     * @param string $mainDomain
     * @return void
     */
    private function copyModules(array $modules, string $mainDomain): void
    {
        foreach ($modules as $vendorName => $vendorModules) {
            $vendorDirectory = 'app' .
                DIRECTORY_SEPARATOR .
                'code' .
                DIRECTORY_SEPARATOR .
                $vendorName;
            $this->shell->dockerExec("mkdir -p {$vendorDirectory} ", $mainDomain);

            foreach ($vendorModules as $moduleName => $moduleData) {
                $this->shell->passthru("cp -R {$moduleData['path']} {$vendorDirectory}");
            }
        }
    }
}
