<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractTestCommand extends \Symfony\Component\Console\Command\Command implements
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    use \DefaultValue\Dockerizer\Console\CommandLoggerTrait;

    /**
     * Avoid domains intersection if template metadata matches for any reason. Useful for multithread tests
     *
     * @var array $testedDomains
     */
    private array $testedDomains = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        private string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initLogger($this->dockerizerRootDir);

        return self::SUCCESS;
    }

    /**
     * Generate callback for multithreading
     *
     * @param string $magentoVersion
     * @param array $servicesCombination
     * @param string $templateCode
     * @return callable
     */
    protected function getMagentoInstallCallback(
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
            // Reinit logger to have individual name for every callback
            // This way we can identify logs for every callback
            $this->initLogger($this->dockerizerRootDir, uniqid('', false));
            $testUrl = "https://$domain/";
            $environment = array_rand(['dev' => true, 'prod' => true, 'staging' => true]);
            $projectRoot = $this->createProject->getProjectRoot($domain);
            register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);

            // @TODO: change this in some better way!!!
            $initialPath = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), -1)[0]['file'];
            $command = "php $initialPath magento:setup";
            $command .= " $magentoVersion";
            $command .= " --with-environment=$environment";
            $command .= " --domains='$domain www.$domain'";
            $command .= ' --template=' . $templateCode;
            $command .= " --required-services='$requiredServices'";
            $command .= " --optional-services='$optionalServices'";
            $command .= ' -n -f -q';

            try {
                $this->logger->info("$magentoVersion - $debugData > $testUrl - started");
                $this->logger->debug($command);
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

                $this->logger->info("$magentoVersion - $debugData > $testUrl - completed");
            } catch (\Exception $e) {
                $this->logger->error("$magentoVersion - $debugData > $testUrl - \n>>> FAILED!");
                $this->logger->error($command);
                $this->logger->error("Error message: {$e->getMessage()}");
                throw $e;
            }
        };
    }

    /**
     * @param string $testUrl
     * @return int
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function getStatusCode(string $testUrl): int
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

        $this->logger->notice("Retries left for $testUrl - $retries");

        return $statusCode;
    }

    /**
     * Switch off composition and remove files even in case the process was terminated (CTRL + C)
     * Similar to CreateProject::cleanUp(). Maybe need to move elsewhere
     *
     * @param string $projectRoot
     * @return void
     */
    protected function cleanUp(string $projectRoot): void
    {
        $this->logger->info('Trying to shut down composition...');

        foreach ($this->compositionCollection->getList($projectRoot) as $dockerCompose) {
            $dockerCompose->down();
        }

        $this->shell->run("rm -rf $projectRoot");
        $this->logger->info('Shutdown completed!');
    }
}
