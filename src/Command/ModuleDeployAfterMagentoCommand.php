<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ModuleDeployAfterMagentoCommand extends AbstractCommand
{
    private static $magentoDirectoriesAndFilesToClean = [
        './app/etc/config.php',
        './app/etc/config.php',
        './generated/code/*',
        './generated/metadata/*',
        './var/di/*',
        './var/generation/*',
        './var/cache/*',
        './var/page_cache/*',
        './var/view_preprocessed/*',
        './pub/static/*',


    ];

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $this->setName('module:deploy-after-magento')
            ->setDescription('<info>Re-install Magento 2 application, copy module files upgrade. Run \'magento:setup\' first</info>');
            // @TODO: add help
            // ->setHelp('');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = microtime(true);

        $projectRoot = getcwd();
        $directoryName = basename($projectRoot);

        # Stage 1: copy files, deploy Sample Data, create database
        if (!file_exists('./app') || !is_dir('./app')) {
            $output->writeln('<error>Seems we\'re not inside the Magento project</error>');
            exit;
        }

        // @TODO: use composer require to install from the repository
        // @TODO: add directory parameter with all modules
        // @TODO: find all etc/module.xml, get vendor-module, create folders, copy

        $output->writeln('<info>Cleanup Magento 2 application...</info>');

        $this->dockerExec("sh -c \"rm -rf dirlisthere\"");
        $directoriesToClean = [

        ];

        // @TODO: additional directories to remove
        // @TODO: do we need additional external packages to require? Or probably must get them from the composer.json of the installed modules???????????

        passthru(<<<BASH
            rm -rf ./app/code/Vendor
            rm -rf ./pub/media/vendor/
            mkdir -p ./app/code/Vendor
BASH
        );

        $output->writeln('<info>Deploy Sample Data...</info>');

        if (!file_exists('./var/.sample-data-state.flag')) {
            $this->dockerExec('php bin/magento sampledata:deploy');
        }

        passthru('chmod +x ./bin/magento');

        # Stage 2: install application, switch to the production mode
        $output->writeln('<info>Refresh DB and install...</info>');
        $this->refreshDbAndInstall();

        $this->updateMagentoConfig();

        $this->dockerExec('php bin/magento cache:disable full_page block_html')
            ->dockerExec('php bin/magento deploy:mode:set production')
            ->dockerExec('php bin/magento indexer:reindex');

        $output->writeln('<info>Copy and commit Detailed Review...</info>');

        passthru(<<<BASH
            cp -r /misc/apps/drm2/Vendor/* ./app/code/Vendor/
            # rm -rf ./app/code/Vendor/Module
            git config core.fileMode false
            git add ./app/code/Vendor
            git add -u ./app/code/Vendor
            git commit -m "New build"
BASH
        );

        $output->writeln('<info>Run setup:upgrade with DRM2...</info>');
        // passthru('rm -rf ./var/di/'); // bug with compilation in Magento 2.1 only ?
        $this->dockerExec('php bin/magento setup:upgrade');

        $output->writeln('<info>Switching to the production mode...</info>');

        $this->dockerExec('php bin/magento deploy:mode:set production');

        $output->writeln('<info>Running final reindex...</info>');
        $this->dockerExec('php bin/magento indexer:reindex');

        $endTime = microtime(true);
        $minutes = floor(($endTime - $startTime) / 60);
        $seconds = round($endTime - $startTime - $minutes * 60, 2);
        $output->writeln("<info>Completed in $minutes minutes $seconds seconds!</info>");
        $baseUrl = 'https://' . $directoryName;
        $output->writeln("<info>Frontend: $baseUrl</info>");
        $output->writeln("<info>Admin Panel: $baseUrl</info>");
    }
}
