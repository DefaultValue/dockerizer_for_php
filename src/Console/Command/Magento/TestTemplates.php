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

use Composer\Semver\Semver;
use DefaultValue\Dockerizer\Docker\Compose;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use DefaultValue\Dockerizer\Platform\Magento\AppContainers;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class TestTemplates extends AbstractTestCommand
{
    protected static $defaultName = 'magento:test-templates';

    public const MAGENTO_MEMORY_LIMIT_IN_GB = 2.5;

    /**
     * Lower and upper version for every system requirements change
     *
     * @var string[] $versionsToTest
     */
    private array $versionsToTest = [
        '2.0.2',
        '2.0.18',
        '2.1.0',
        '2.1.18',
        '2.2.0',
        '2.2.11',
        '2.3.0',
        '2.3.1',
        '2.3.2',
        '2.3.3',
        '2.3.4',
        '2.3.5',
        '2.3.6',
        '2.3.7',
        '2.3.7-p2',
        '2.3.7-p3',
        '2.4.0',
        '2.4.1',
        '2.4.2',
        '2.4.3',
        '2.4.3-p1',
        '2.4.3-p2',
        '2.4.3-p3',
        '2.4.4',
        '2.4.4-p1',
        '2.4.5'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Platform\Magento $magento,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $createProject,
            $compositionCollection,
            $shell,
            $filesystem,
            $httpClient,
            $dockerizerRootDir,
            $name
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('Test Magento templates')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> tests Magento templates by installing Magento with various services.
                Templates MUST have default values for all service parameters.
                Note that execution may fail due to the network issues or lack of resources. In such case you can take
                the command from the log file and test it manually.
                EOF);

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
        $servicesCombinationsByMagentoVersion = [];

        foreach (array_reverse($this->versionsToTest) as $versionToTest) {
            $templates = $this->templateCollection->getRecommendedTemplates(SetUp::MAGENTO_CE_PACKAGE, $versionToTest);
            $servicesCombinationsByMagentoVersion[$versionToTest] = [];

            /** @var Template $template */
            foreach ($templates as $template) {
                $servicesCombinationsByMagentoVersion[$versionToTest][$template->getCode()]
                    = $this->combineServices($template);
            }
        }

        $callbacks = [];

        foreach ($servicesCombinationsByMagentoVersion as $magentoVersion => $combinationsByTemplate) {
            foreach ($combinationsByTemplate as $templateCode => $servicesCombinations) {
                foreach ($servicesCombinations as $servicesCombination) {
                    $callbacks[] = $this->getMagentoInstallCallback(
                        $magentoVersion,
                        $templateCode,
                        $servicesCombination,
                        \Closure::fromCallable([$this, 'afterInstallCallback']) // add option for full test
                    );
                }
            }
        }

        // Limit to 6 threads. SSD may not be fine to handle more load
        $signalRegistry = $this->getApplication()?->getSignalRegistry()
            ?? throw new \LogicException('Application is not initialized');
        $this->multithread->run($callbacks, $output, $signalRegistry, self::MAGENTO_MEMORY_LIMIT_IN_GB, 6);
        //$this->multithread->run([array_shift($callbacks)], $output, $signalRegistry, 10, 999);
        //$this->multithread->run([$callbacks[0], $callbacks[1]], $output, $signalRegistry, 4, 6);

        $output->writeln('Test completed!');

        return self::SUCCESS;
    }

    /**
     * Prepare a random combination of services to test every service at least once
     *
     * @param Template $template
     * @return array
     */
    private function combineServices(Template $template): array
    {
        $allServices = [];
        $servicesByType = [
            Service::TYPE_REQUIRED => [],
            Service::TYPE_OPTIONAL => [],
        ];
        $totalCombinations = 0;

        foreach ($template->getServices(Service::TYPE_REQUIRED) as $groupName => $services) {
            $allServices[$groupName] = array_keys($services);
            $servicesByType[Service::TYPE_REQUIRED][] = array_keys($services);
            $totalCombinations = count($services) > $totalCombinations ? count($services) : $totalCombinations;
        }

        foreach ($template->getServices(Service::TYPE_OPTIONAL) as $groupName => $services) {
            $allServices[$groupName] = array_keys($services);
            $servicesByType[Service::TYPE_OPTIONAL][] = array_keys($services);
            $totalCombinations = count($services) > $totalCombinations ? count($services) : $totalCombinations;
        }

        $servicesByType[Service::TYPE_REQUIRED] = array_merge(...$servicesByType[Service::TYPE_REQUIRED]);
        $servicesByType[Service::TYPE_OPTIONAL] = array_merge(...$servicesByType[Service::TYPE_OPTIONAL]);

        // Pad all array to the specified length and shuffle the array
        foreach ($allServices as $groupName => $originalServices) {
            $services = $originalServices;

            while (count($services) < $totalCombinations) {
                $services[] = $originalServices[array_rand($originalServices)];
            }

            shuffle($services);
            $allServices[$groupName] = $services;
        }

        $combinations = [];

        while ($totalCombinations--) {
            $unsortedServices = array_column($allServices, $totalCombinations);
            $services = [];

            foreach ($unsortedServices as $service) {
                if (in_array($service, $servicesByType[Service::TYPE_REQUIRED], true)) {
                    $services[Service::TYPE_REQUIRED][] = $service;
                } else {
                    $services[Service::TYPE_OPTIONAL][] = $service;
                }
            }

            $combinations[] = $services;
        }

        return $combinations;
    }

    /**
     * Test templates incl. running dev tools composition
     *
     * @param string $domain
     * @param string $projectRoot
     * @return void
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    protected function afterInstallCallback(string $domain, string $projectRoot): void
    {
        chdir($projectRoot);
        $dockerCompose = array_values($this->compositionCollection->getList($projectRoot))[0];
        // We can init Magento here, because even after switching containers their names
        // and DB credentials stay the same. Thus, this is an additional test to ensure env is consistent
        $appContainers = $this->magento->initialize($dockerCompose, $projectRoot);

        $testAndEnsureMagentoIsAlive = function (callable $test, ...$args) use ($domain, $appContainers) {
            $test(...$args);
            $this->testResponseIs200ok("https://$domain/", 'Can\'t start composition with dev tools!');
            $this->testDatabaseAvailability($appContainers);
        };

        // $testAndEnsureMagentoIsAlive([$this, 'checkMysqlSettings'], $appContainers); return;
        $testAndEnsureMagentoIsAlive([$this, 'switchToDevTools'], $dockerCompose);
        $testAndEnsureMagentoIsAlive([$this, 'checkXdebugIsLoadedAndConfigured'], $appContainers);
        $testAndEnsureMagentoIsAlive([$this, 'dumpDbAndRestart'], $dockerCompose, $appContainers, $domain);
        $testAndEnsureMagentoIsAlive([$this, 'generateFixturesAndReindex'], $appContainers);
        $testAndEnsureMagentoIsAlive([$this, 'reinstallMagento']);
        // Remove `installAndRunGrunt` for hardware tests, because network delays may significantly affect the result
        $testAndEnsureMagentoIsAlive([$this, 'installAndRunGrunt'], $appContainers);

        $this->logger->info('Additional test passed!');
    }

    /**
     * We must ensure that `my.cnf` files are located correctly and MySQL accepts them
     * This is a special test to check that "innodb_buffer_pool_size" and "datadir" settings are applied
     *
     * @param AppContainers $appContainers
     * @return void
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function checkMysqlSettings(AppContainers $appContainers): void
    {
        $this->logger->info('Check MySQL datadir is set to \'/var/lib/mysql_datadir/\'');
        /** @var Mysql $mysqlService */
        $mysqlService = $appContainers->getService(AppContainers::MYSQL_SERVICE);

        try {
            // Bitnami's images do not create a data volume in their entrypoint scripts! Volume is required to save data
            // Thus, there is also no need to set `datadir` for Bitnami images to commit DB data
            $mysqlService->mustRun('ls -la /opt/bitnami/mariadb/conf/my.cnf', Shell::EXECUTION_TIMEOUT_SHORT, false);

            return;
        } catch (ProcessFailedException) {
        }

        $statement = $mysqlService->prepareAndExecute('SHOW VARIABLES LIKE \'datadir\'');
        $datadir = $statement->fetchAll()[0][1];

        if ($datadir !== '/var/lib/mysql_datadir/') {
            throw new \RuntimeException('MySQL \'datadir\' is expected to be \'/var/lib/mysql_datadir/\'!');
        }

        $statement = $mysqlService->prepareAndExecute('SHOW VARIABLES LIKE \'innodb_buffer_pool_size\'');
        $innodbBufferPoolSize = (int) $statement->fetchAll()[0][1];

        if ($innodbBufferPoolSize !== 1073741824) {
            throw new \RuntimeException('MySQL \'innodb_buffer_pool_size\' is expected to be 1073741824 bytes (1GB)!');
        }
    }

    /**
     * @param Compose $dockerCompose
     * @return void
     */
    private function switchToDevTools(Compose $dockerCompose): void
    {
        $this->logger->info('Starting additional tests...');
        $this->logger->info('Restart composition with dev tools');
        // Maybe lets also test phpMyAdmin and MailHog?
        $dockerCompose->down(false);
        $dockerCompose->up();
    }

    /**
     * @param AppContainers $appContainers
     * @return void
     * @throws \Exception
     */
    private function checkXdebugIsLoadedAndConfigured(AppContainers $appContainers): void
    {
        $this->logger->info('Check xdebug is loaded and configured');
        $phpContainer = $appContainers->getService(AppContainers::PHP_SERVICE);
        $process = $phpContainer->mustRun('php -i | grep xdebug', Shell::EXECUTION_TIMEOUT_SHORT, false);

        if (!str_contains($process->getOutput(), 'host.docker.internal')) {
            throw new \RuntimeException(
                'xDebug is not installed or is misconfigured: ' . trim($process->getOutput())
            );
        }
    }

    /**
     * @param AppContainers $appContainers
     * @return void
     */
    private function generateFixturesAndReindex(AppContainers $appContainers): void
    {
        // Realtime reindex while generating fixtures takes time, especially for Magento < 2.2.0
        $this->logger->info('Switch indexer to the schedule mode, generate fixtures');
        $appContainers->runMagentoCommand('indexer:set-mode schedule', true);
        $appContainers->runMagentoCommand(
            'setup:perf:generate-fixtures /var/www/html/setup/performance-toolkit/profiles/ce/small.xml',
            true,
            Shell::EXECUTION_TIMEOUT_LONG
        );
        $this->logger->info('Switch indexer to the realtime mode, run reindex');
        $appContainers->runMagentoCommand('indexer:set-mode realtime', true);
        // Can take some time under the high load
        $appContainers->runMagentoCommand('indexer:reindex', true, Shell::EXECUTION_TIMEOUT_LONG);
    }

    /**
     * Ensure that DB dump can be automatically deployed with entrypoint scripts
     *
     * @param Compose $dockerCompose
     * @param AppContainers $appContainers
     * @param string $domain
     * @return void
     * @throws TransportExceptionInterface
     */
    private function dumpDbAndRestart(Compose $dockerCompose, AppContainers $appContainers, string $domain): void
    {
        $this->logger->info('Create DB dump and restart composition');
        $appContainers->runMagentoCommand('indexer:set-mode schedule', true);
        /** @var Mysql $mysqlService */
        $mysqlService = $appContainers->getService(AppContainers::MYSQL_SERVICE);
        $destination = $dockerCompose->getCwd() . DIRECTORY_SEPARATOR . 'mysql_initdb' . DIRECTORY_SEPARATOR
            . $mysqlService->getMysqlDatabase() . '.sql.gz' ;
        $mysqlService->dump($destination);

        // Stop and remove volumes
        $dockerCompose->down();
        // Start and force MySQL to deploy a DB from the dump
        $dockerCompose->up();

        // Wait till DB is ready before we can switch indexer mode and ensure definer is not an issue
        // @TODO: find a better way to do this - use docker logs, processlist or something else
        $this->testResponseIs200ok(
            "https://$domain/",
            'Can\'t start magento after restarting composition and extracting DB!',
            300
        );
        $this->testDatabaseAvailability($appContainers);

        $this->logger->info('Try switching indexer modes after restarting composition and extracting DB dump');
        $appContainers->runMagentoCommand('indexer:set-mode realtime', true);
        $appContainers->runMagentoCommand('indexer:set-mode schedule', true);
    }

    /**
     * @return void
     * @throws ExceptionInterface
     */
    private function reinstallMagento(): void
    {
        $this->logger->info('Reinstall Magento');
        $command = $this->getApplication()?->find('magento:reinstall')
            ?? throw new \LogicException('Application is not initialized');
        $input = new ArrayInput([
            '-n' => true,
            '-q' => true
        ]);
        $input->setInteractive(false);
        $command->run($input, new NullOutput());
    }

    /**
     * @param AppContainers $appContainers
     * @return void
     */
    private function installAndRunGrunt(AppContainers $appContainers): void
    {
        $this->logger->info('Test Grunt');
        $phpContainer = $appContainers->getService(AppContainers::PHP_SERVICE);
        $appContainers->runMagentoCommand('deploy:mode:set developer', true);

        // File names before 2.1.0 are `package.json` and `Gruntfile.js`
        if (Semver::satisfies($appContainers->getMagentoVersion(), '>=2.1.0')) {
            $phpContainer->mustRun('cp package.json.sample package.json');
            $phpContainer->mustRun('cp Gruntfile.js.sample Gruntfile.js');
        }

        $phpContainer->mustRun('npm install --save-dev', Shell::EXECUTION_TIMEOUT_LONG, false);
        $phpContainer->mustRun('grunt clean:luma', Shell::EXECUTION_TIMEOUT_SHORT, false);
        $phpContainer->mustRun('grunt exec:luma', Shell::EXECUTION_TIMEOUT_SHORT, false);
        $phpContainer->mustRun('grunt less:luma', Shell::EXECUTION_TIMEOUT_SHORT, false);
    }

    /**
     * @param AppContainers $appContainers
     * @return void
     */
    private function testDatabaseAvailability(AppContainers $appContainers): void
    {
        $retries = 60;

        while ($retries) {
            try {
                $appContainers->runMagentoCommand('indexer:show-mode', true, Shell::EXECUTION_TIMEOUT_SHORT, false);
                $this->logger->notice(
                    "$retries of 60 retries left to check DB availability"
                );

                return;
            } catch (ProcessFailedException) {
            }

            --$retries;
            sleep(1);
        }

        throw new \RuntimeException();
    }
}
