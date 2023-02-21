<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Platform\Magento;
use DefaultValue\Dockerizer\Platform\Magento\AppContainers;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

// @TODO: ask for confirmation if there are uncommitted changes! Or at least check that source and target
// dirs do not intersect
/**
 * @noinspection PhpUnused
 */
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
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param Magento $magento
     * @param Magento\SetupInstall $setupInstall
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
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
        $this->setDescription('Refresh module files and re-install Magento 2 application')
            ->addArgument(
                self::ARGUMENT_MODULE_DIRECTORIES,
                InputArgument::IS_ARRAY,
                'Directory with Magento 2 modules'
            )
            ->addOption(
                self::OPTION_TOGETHER,
                't',
                InputOption::VALUE_NONE,
                'Copy module files before running Magento setup'
            )
            // phpcs:disable Generic.Files.LineLength.TooLong
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command allows to test installing modules on the existing Magento 2 instance. Use the command from the Magento root folder.

                Usage:

                1) Common flow - install Sample Data, reinstall Magento, install module:

                    <info>php %command.full_name% /folder/with/module(s) /another/folder/with/module(s)</info>

                2) CI/CD-like flow - install Sample Data, copy module(s) inside Magento, reinstall Magento:

                    <info>php %command.full_name% /folder/with/module(s) /another/folder/with/module(s) --together</info>

                Remember that all files are copied, disregarding the `.gitignore` or any other limitations.
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        # Step 1: Validate Magento and modules
        $startTime = microtime(true);
        $modules = $this->getModules($input->getArgument(self::ARGUMENT_MODULE_DIRECTORIES));
        $output->writeln('<info>Modules list:</info>');

        foreach ($modules as $vendor => $modulesList) {
            foreach (array_keys($modulesList) as $moduleName) {
                $output->writeln(sprintf('- <info>%s_%s</info>', $vendor, $moduleName));
            }
        }

        $output->writeln('');
        $projectRoot = getcwd() . DIRECTORY_SEPARATOR;
        $this->magento->validateIsMagento($projectRoot); // Additional validation to ask less question in case of issues
        $composition = $this->selectComposition($input, $output);
        $appContainers = $this->magento->initialize($composition, $projectRoot);
        $phpService = $appContainers->getService(AppContainers::PHP_SERVICE);

        # Step 2: Install Sample Data modules if missed. Do not run `setup:upgrade`
        $sampleDataFlag = '.' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '.sample-data-state.flag';

        if (!$this->filesystem->isFile($sampleDataFlag)) {
            $output->writeln('<info>Deploy Sample Data...</info>');
            $phpService->mustRun('php bin/magento sampledata:deploy', Shell::EXECUTION_TIMEOUT_LONG);
        }

        # Step 3: Clean up Magento 2, reinstall it, handle `--together` option
        $output->writeln('<info>Cleanup Magento 2 application...</info>');

        foreach ($this->magentoDirectoriesAndFilesToClean as $path) {
            $phpService->mustRun("rm -rf $path");
        }

        if ($input->getOption(self::OPTION_TOGETHER)) {
            $output->writeln('<info>Refresh modules, reinstall Magento 2 application...</info>');
            $this->refreshModules($modules);
            $this->setupInstall->setupInstall($output, $composition);
        } else {
            $output->writeln('<info>Reinstall Magento 2 application, refresh modules, run `setup:upgrade`...</info>');
            $this->setupInstall->setupInstall($output, $composition);
            // Must run this here to ensure the module CAN be installed in case Magento is in the `production` mode
            // There are cases when this works fine in the `developer` mode, but fails in `production`
            $phpService->mustRun('php bin/magento deploy:mode:set production', Shell::EXECUTION_TIMEOUT_LONG);
            $phpService->mustRun('php bin/magento indexer:reindex', Shell::EXECUTION_TIMEOUT_LONG);
            $this->refreshModules($modules);
            $phpService->mustRun('php bin/magento setup:upgrade', Shell::EXECUTION_TIMEOUT_LONG);
        }

        $output->writeln('<info>Magento and modules installed successfully!</info>');

        # Step 4: Final switch to production mode
        $output->writeln('<info>Switching to the production mode...</info>');
        $phpService->mustRun('php bin/magento deploy:mode:set production', Shell::EXECUTION_TIMEOUT_LONG);
        $output->writeln('<info>Running reindex...</info>');
        $phpService->mustRun('php bin/magento indexer:reindex', Shell::EXECUTION_TIMEOUT_LONG);

        # Step 5: Commit changes
        $output->writeln('<info>Commit changes...</info>');
        $this->shell->mustRun('git config core.fileMode false');
        $this->shell->mustRun('git add ./app/code/*');
        $this->shell->mustRun('git add -u ./app/code/*');

        // Exit code is 1 if there are files staged to commit
        if ($this->shell->run('git diff --cached --exit-code')->getExitCode()) {
            $this->shell->mustRun('git commit -m "New build"');
        } else {
            $output->writeln('<info>There seems to be nothing new to commit</info>');
        }

        $endTime = microtime(true);
        $minutes = floor(($endTime - $startTime) / 60);
        $seconds = round($endTime - $startTime - $minutes * 60, 2);
        $output->writeln("<info>Completed in $minutes minutes $seconds seconds!</info>");

        return self::SUCCESS;
    }

    /**
     * @param array $moduleDirectories
     * @return array
     */
    private function getModules(array $moduleDirectories): array
    {
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
     * @TODO: pack modules in the the archives and install them via composer to ensure `composer.json` is correct! :)
     *
     * @param array $modules
     * @return void
     */
    private function refreshModules(array $modules): void
    {
        foreach ($modules as $vendor => $modulesList) {
            $vendorDirectory = 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . $vendor;
            $this->shell->mustRun("mkdir -p $vendorDirectory");

            foreach ($modulesList as $moduleName => $source) {
                $destination = $vendorDirectory . DIRECTORY_SEPARATOR . $moduleName;
                $this->shell->mustRun("cp -R $source $destination");
            }
        }
    }
}
