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
use Symfony\Component\Console\Output\NullOutput;

/**
 * Mutithread tests for Magento
 */
abstract class AbstractTestCommand extends \DefaultValue\Dockerizer\Console\Command\Composition\AbstractTestCommand
{
    /**
     * Avoid domains intersection if template metadata matches for any reason. Useful for multithread tests
     *
     * @var string[] $testedDomains
     */
    private array $testedDomains = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        private string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $shell,
            $filesystem,
            $httpClient,
            $dockerizerRootDir,
            $name
        );
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
            $this->registerCleanupAsShutdownFunction($projectRoot);
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

                $command = $this->getApplication()?->find('magento:setup')
                    ?? throw new \LogicException('Application is not initialized');
                // Suppress all output, only log exceptions
                $arrayInput = new ArrayInput($input);
                $arrayInput->setInteractive(false);
                $command->run($arrayInput, new NullOutput());

                // Run healthcheck by requesting a cacheable page, output command and notify later if failed
                // Test in production mode
                $this->testResponseIs200ok($testUrl, "No valid response from $testUrl");
                $this->logger->info("Installation successful: $debugData");

                if (is_callable($afterInstallCallback)) {
                    $afterInstallCallback($domain, $projectRoot);
                }

                $this->logger->info("Completed all test for: $debugData");
            } catch (\Throwable $e) {
                $this->logThrowable($e, $debugData);

                throw $e;
            }
        };
    }
}
