<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        '2.4.4'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $createProject,
            $shell,
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
        $this->setDescription('<info>Test Magento templates</info>')
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
}
