<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Platform\Magento;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class TestModuleInstall extends \DefaultValue\Dockerizer\Console\Command\AbstractCompositionAwareCommand
{
    protected static $defaultName = 'magento:test-module-install';

    private const ARGUMENT_MODULE_DIRECTORIES = 'module-directories';

    private const OPTION_TOGETHER = 'together';

    /**
     * Cleanup next directories
     * @var array
     */
    private array $magentoDirectoriesAndFilesToClean = [
        // 'app/etc/config.php',
        // 'app/etc/env.php',
        'app/code/*', // @TODO: remove only known modules!!! Keep Core and other installed modules?
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
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param Magento $magento
     * @param Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Platform\Magento $magento,
        private \DefaultValue\Dockerizer\Platform\Magento\SetupInstall $setupInstall,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($compositionCollection, $availableCommandOptions, $name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('<info>Refresh module files and re-install Magento 2 application</info>')
            ->addArgument(
                self::ARGUMENT_MODULE_DIRECTORIES,
                InputArgument::IS_ARRAY,
                'Directory with Magento 2 modules'
            )->addOption(
                self::OPTION_TOGETHER,
                't',
                InputOption::VALUE_NONE,
                'Copy module files before running Magento setup'
            )
            // phpcs:disable Generic.Files.LineLength
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command allows to test installing modules on the existing Magento 2 instance. Use the command from the Magento root folder.

                Usage:

                1) Common flow - install Sample Data, reinstall Magento, install module:

                    <info>php %command.full_name% /folder/with/modules</info>

                2) CI/CD-like flow - install Sample Data, copy module(s) inside Magento, reinstall Magento:

                    <info>php %command.full_name% /folder/with/modules --together</info>
                EOF);
            // phpcs:enable Generic.Files.LineLength
        parent::configure();
    }

    /**
     * @param ArgvInput $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $modules = $this->validateMagentoAndModules($input->getArgument(self::ARGUMENT_MODULE_DIRECTORIES));
        $output->writeln('<info>Modules list:</info>');

        foreach ($modules as $vendor => $modulesList) {
            foreach ($modulesList as $moduleName => $moduleInfo) {
                $output->writeln("- <info>{$vendor}_{$moduleName}</info>");
            }
        }

        # Stage 0: clean Magento 2, install Magento application, handle together attribute
        $output->writeln('<info>Cleanup Magento 2 application...</info>');
        $composition = $this->selectComposition($input, $output);
        $magento = $this->magento->initialize($composition, getcwd());
        $phpService = $magento->getService(Magento::PHP_SERVICE);

        foreach ($this->magentoDirectoriesAndFilesToClean as $path) {
            $phpService->mustRun("rm -rf $path");
        }

        if ($together = $input->getOption(self::OPTION_TOGETHER)) {
            $output->writeln('<info>Copying modules to run installation together with the Magento...</info>');
            $this->refreshModules($modules);
        }

        // $this->setupInstall->setupInstall($output, $composition);


throw new \Exception('To be refactored');



        // Get all required info about the instance before other actions
        $magentoVersion = $magentoComposerFile->require->{'magento/product-community-edition'};
        $elasticsearchHost = version_compare($magentoVersion, '2.4.0', 'lt') ? '' : 'elasticsearch';
        $phpVersion = substr($this->shell->exec("docker exec $mainDomain php -r 'echo phpversion();'")[0], 0, 3);
        // Really poor way to do this
        $mysqlContainer = $this->shell->exec('docker-compose config | grep :mysql')[0];
        $mysqlContainer = trim(str_replace(' - ', '', explode(':', $mysqlContainer)[0]));
        $this->database->connect($mysqlContainer);



        $output->writeln('<info>Reinstalling Magento 2 application...</info>');
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
            $this->refreshModules($modules, $mainDomain);

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

        return self::SUCCESS;
    }

    /**
     * @param array $moduleDirectories
     * @return array
     */
    private function validateMagentoAndModules(array $moduleDirectories): array
    {
        $this->magento->validateIsMagento();
        $modules = [];

        foreach (Finder::create()->in($moduleDirectories)->path('etc')->name('module.xml')->files() as $fileInfo) {
            $moduleInfoXml = simplexml_load_string($this->filesystem->fileGetContents($fileInfo->getRealPath()));
            $moduleName = $moduleInfoXml->module->attributes()->name;
            $explodedModuleName = explode('_', (string) $moduleName);

            if (isset($modules[$explodedModuleName[0]][$explodedModuleName[1]])) {
                throw new \RuntimeException("The same module detected twice: $moduleName");
            }

            $modules[$explodedModuleName[0]][$explodedModuleName[1]] = dirname($fileInfo->getRealPath(), 2);
        }

        if (empty($modules)) {
            throw new \InvalidArgumentException(
                'No Magento 2 modules detected in: ' . implode(', ', $moduleDirectories)
            );
        }

        return $modules;
    }

    /**
     * @param array $modules
     * @return void
     */
    private function refreshModules(array $modules): void
    {
        throw new \Exception('To be refactored');

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
