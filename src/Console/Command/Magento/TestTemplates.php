<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestTemplates extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'magento:test-templates';

    private const MAGENTO_MEMORY_LIMIT_IN_GB = 2.5;

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
        '2.4.4'
    ];

    private string $logFile;

    /**
     * Avoid domains intersection if template metadata matches for any reason
     *
     * @var array $testedDomains
     */
    private array $testedDomains = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('magento:setup')
            ->setDescription('<info>Test Magento templates</info>')
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
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $initialPath =  array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), -1)[0]['file'];
        $this->logFile = dirname($initialPath, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'log' . DIRECTORY_SEPARATOR . 'magento_test.log';
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
                    $callbacks[] = $this->createCallback(
                        $magentoVersion,
                        $templateCode,
                        $servicesCombination,
                    );
                }
            }
        }

        // Limit to 6 threads. SSD may not be fine to handle more load
        $this->multithread->run($callbacks, $output, self::MAGENTO_MEMORY_LIMIT_IN_GB, 6);
//        $this->multithread->run($callbacks, $output, 8, 999);
//        $this->multithread->run([array_shift($callbacks)], $output, 10, 999);
//        $this->multithread->run([$callbacks[0], $callbacks[1]], $output, 4, 6);

        $output->writeln('Test finished!');

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
     * Generate callback for multithreading
     *
     * @param string $magentoVersion
     * @param array $servicesCombination
     * @param string $templateCode
     * @return callable
     */
    private function createCallback(
        string $magentoVersion,
        string $templateCode,
        array $servicesCombination
    ): callable {
        $requiredServices = implode(',', $servicesCombination[Service::TYPE_REQUIRED]);
        $optionalServices = implode(',', $servicesCombination[Service::TYPE_OPTIONAL]);
        $debugData = "$requiredServices,$optionalServices";
        // Domain name must not be more than 64 chars for Nginx!
        // Otherwise, may need to change `server_names_hash_bucket_size`
        $domain = array_reduce(
            preg_split("/[_-]+/", str_replace(['.', '_', ','], '-', "$templateCode-$debugData")),
            static function ($carry, $string) {
                $carry .= $string[0];

                return $carry;
            }
        );

        // Encode Magento version + template code + selected service parameters in the domain name for easier debug
        $domain = 'm' . str_replace('.', '', $magentoVersion) . '-' . $domain . '.l';

        // Domain name must be less than 32 chars! Otherwise, change `server_names_hash_bucket_size` for Nginx
        if (strlen($domain) > 32) {
            $domain = uniqid('m' . str_replace('.', '', $magentoVersion) . '-', false) . '.l';
        }

        if (in_array($domain, $this->testedDomains, true)) {
            $domain = uniqid('m' . str_replace('.', '', $magentoVersion) . '-', false) . '.l';
        }

        $this->testedDomains[] = $domain;

        return function () use (
            $magentoVersion,
            $templateCode,
            $requiredServices,
            $optionalServices,
            $debugData,
            $domain
        ) {
            $testUrl = "https://$domain/";
            $environment = array_rand(['dev' => true, 'prod' => true, 'staging' => true]);
            $projectRoot = $this->createProject->getProjectRoot($domain);
            register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);

            // @TODO: change this in some better way!!!
            $initialPath =  array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), -1)[0]['file'];
            $command = "php $initialPath magento:setup";
            $command .= " $magentoVersion";
            $command .= " --with-environment=$environment";
            $command .= " --domains='$domain www.$domain'";
            $command .= ' --template=' . $templateCode;
            $command .= " --required-services='$requiredServices'";
            $command .= " --optional-services='$optionalServices'";
            $command .= ' -n -f -q';

            try {
                $this->log("$magentoVersion - $debugData > $testUrl - started");
                $this->log($command);
                // Install Magento
                $this->shell->mustRun($command, null, [], '', Shell::EXECUTION_TIMEOUT_LONG);

                // Run healthcheck by requesting a cacheable page, output command and notify later if failed
                // Test in production mode
                if ($this->getStatusCode($testUrl) !== 200) {
                    throw new \RuntimeException("No valid response from $testUrl");
                }

                // Test with dev tools as well. Just in case
                // Seems it fails because containers take too much time to start (MySQL and\or Elasticsearch)
                /*
                $dockerCompose->down(false);
                $dockerCompose->up();

                if ($this->getStatusCode($testUrl) !== 200) {
                    throw new \RuntimeException("No valid response from $testUrl");
                }
                */

                $this->log("$magentoVersion - $debugData > $testUrl - completed");
            } catch (\Exception $e) {
                $this->log("$magentoVersion - $debugData > $testUrl - \n>>> FAILED!");
                $this->log($command);
                $this->log("Error message: {$e->getMessage()}");
                throw $e;
            }
        };
    }

    /**
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        // ~/misc/apps/dockerizer_for_php/var/log/magento_test.log
        file_put_contents($this->logFile, date('Y-m-d_H:i:s') . ': ' . $message . "\n", FILE_APPEND);
    }

    /**
     * @param string $testUrl
     * @return int
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function getStatusCode(string $testUrl): int
    {
        // Starting containers and running healthcheck may take quite long, especially in the multithread test
        $retries = 60;
        $statusCode = 500;

        while ($retries && $statusCode !== 200) {
            //  @TODO: replace with CURL if this is still a single place where we use `symfony/http-client`
            $statusCode = $this->httpClient->request('GET', $testUrl)->getStatusCode();
            --$retries;

            if ($statusCode !== 200) {
                sleep(1);
            }
        }

        $this->log("Retries left for $testUrl - $retries");

        return $statusCode;
    }

    /**
     * Switch off composition and remove files even in case the process was terminated (CTRL + C)
     * Similar to CreateProject::cleanUp(). Maybe need to move elsewhere
     *
     * @param string $projectRoot
     * @return void
     */
    private function cleanUp(string $projectRoot): void
    {
        $this->log('Trying to shut down composition...');

        foreach ($this->composition->getDockerComposeCollection($projectRoot) as $dockerCompose) {
            $dockerCompose->down();
        }

        $this->shell->run("rm -rf $projectRoot");
        $this->log('Shutdown completed!');
    }
}
