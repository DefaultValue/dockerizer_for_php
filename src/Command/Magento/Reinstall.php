<?php

declare(strict_types=1);

namespace App\Command\Magento;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Reinstall extends \Symfony\Component\Console\Command\Command
{
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
        $this->setName('magento:reinstall')
            ->setDescription(
                '<info>Re-install Magento 2 application</info>'
            )->setHelp(<<<'EOF'
The <info>%command.name%</info> command allows reinstalling Magneto app, especially for testing modules installation
on the existing Magento 2 instance. Use the command in the Magento root folder.

Usage:

    <info>php %command.full_name%</info>

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

        // Get all required info about the instance before other actions
        $magentoVersion = $magentoComposerFile->require->{'magento/product-community-edition'};
        $elasticsearchHost = version_compare($magentoVersion, '2.4.0', 'lt') ? '' : 'elasticsearch';
        $phpVersion = substr($this->shell->exec("docker exec $mainDomain php -r 'echo phpversion();'")[0], 0, 3);
        // Really poor way to do this
        $mysqlContainer = $this->shell->exec('docker-compose config | grep :mysql')[0];
        $mysqlContainer = trim(str_replace(' - ', '', explode(':', $mysqlContainer)[0]));
        $this->database->connect($mysqlContainer);

        # Stage 0: clean Magento 2, install Magento application, handle together attribute
        $output->writeln('<info>Cleanup Magento 2 application...</info>');
        $filesAndFolderToRemove = implode(' ', $this->magentoDirectoriesAndFilesToClean);
        $this->shell->dockerExec("sh -c \"rm -rf {$filesAndFolderToRemove}\"", $mainDomain);

        $output->writeln('<info>Reinstalling Magento 2 application...</info>');
        $this->magentoInstaller->refreshDbAndInstall(
            $mainDomain,
            $magentoVersion === '2.4.0' && $phpVersion === '7.3',
            $elasticsearchHost
        );
        $this->magentoInstaller->updateMagentoConfig($mainDomain);

        $endTime = microtime(true);
        $minutes = floor(($endTime - $startTime) / 60);
        $seconds = round($endTime - $startTime - $minutes * 60, 2);
        $output->writeln("<info>Completed in {$minutes} minutes {$seconds} seconds!</info>");
        $output->writeln("<info>URL: https://$mainDomain/</info>");

        return 0;
    }
}
