<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\OptionalServices as CommandOptionOptionalServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\RequiredServices as CommandOptionRequiredServices;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\CompositionTemplate
    as CommandOptionCompositionTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
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
     * @param string $templateCode
     * @param array $servicesCombination
     * @param callable|null $afterInstallCallback
     * @return callable
     */
    protected function getMagentoInstallCallback(
        string $magentoVersion,
        string $templateCode,
        array $servicesCombination,
        ?callable $afterInstallCallback = null
    ): callable {
        $requiredServices = implode(',', $servicesCombination[Service::TYPE_REQUIRED]);
        $optionalServices = implode(',', $servicesCombination[Service::TYPE_OPTIONAL]);
        $debugData = "$magentoVersion > $requiredServices,$optionalServices";
        // Domain name must not be more than 32 chars for Nginx!
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
        $input = [
            'command' => 'magento:setup',
            SetUp::INPUT_ARGUMENT_MAGENTO_VERSION => $magentoVersion,
            '--' . CommandOptionCompositionTemplate::OPTION_NAME => $templateCode,
            '--' . CommandOptionDomains::OPTION_NAME => "$domain www.$domain",
            '--' . CommandOptionRequiredServices::OPTION_NAME => $requiredServices,
            '--' . CommandOptionOptionalServices::OPTION_NAME => $optionalServices,
            '--' . CommandOptionForce::OPTION_NAME => true,
            '-n' => true,
            '-q' => true,
            // Always add `--with-` options at the end
            // Options are not sorted if a command is called from another command
        ];

        return function () use (
            $domain,
            $input,
            $debugData,
            $afterInstallCallback
        ) {
            // Re-init logger to have individual name for every callback that is run as a child process
            // This way we can identify logs for every callback
            $this->initLogger($this->dockerizerRootDir);
            $testUrl = "https://$domain/";
            $projectRoot = $this->createProject->getProjectRoot($domain);
            register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);
            $input['--with-environment'] = array_rand(['dev' => true, 'prod' => true, 'staging' => true]);

            try {
                $this->logger->info("Started: $debugData");
                $inlineCommand = '';

                foreach ($input as $key => $value) {
                    $inlineCommand .= ' ';
                    $inlineCommand .= str_starts_with($key, '-') ? $key : '';
                    $inlineCommand .= str_starts_with($key, '-') && is_string($value) ? '=' : '';
                    $inlineCommand .= is_string($value) ? escapeshellarg($value) : '';
                }

                $initialPath = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), -1)[0]['file'];
                $inlineCommand = sprintf('%s %s %s', PHP_BINARY, $initialPath, $inlineCommand);
                $this->logger->debug($inlineCommand);

                $command = $this->getApplication()->find('magento:setup');
                // Suppress all output, only log exceptions
                $arrayInput = new ArrayInput($input);
                $arrayInput->setInteractive(false);
                $command->run($arrayInput, new NullOutput());

                // Run healthcheck by requesting a cacheable page, output command and notify later if failed
                // Test in production mode
                if ($this->getStatusCode($testUrl) !== 200) {
                    throw new \RuntimeException("No valid response from $testUrl");
                }

                $this->logger->info("Installation successful: $debugData");

                if (is_callable($afterInstallCallback)) {
                    $afterInstallCallback($domain, $projectRoot);
                }
            } catch (\Throwable $e) {
                $this->logger->emergency("FAILED! $debugData");
                // Render exception and write it to the log file with backtrace
                $output = new BufferedOutput();
                $output->setVerbosity($output::VERBOSITY_VERY_VERBOSE);
                $this->getApplication()->renderThrowable($e, $output);
                $this->logger->emergency($output->fetch());

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
     * @TODO: Similar to CreateProject::cleanUp(). Need to move elsewhere
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
