<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Mutithread tests for Magento
 */
abstract class AbstractTestCommand extends \Symfony\Component\Console\Command\Command implements
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    use \DefaultValue\Dockerizer\Console\CommandLoggerTrait;

    /**
     * Skip cleanup if we made it after catching SIGINT
     *
     * @var bool $skipCleanup
     */
    private bool $skipCleanup = false;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initLogger($this->dockerizerRootDir);

        return self::SUCCESS;
    }

    /**
     * @param string $testUrl
     * @param string $errorMessage
     * @param int $retries
     * @return void
     * @throws TransportExceptionInterface
     * @throws \RuntimeException
     */
    protected function testResponseIs200ok(string $testUrl, string $errorMessage, int $retries = 60): void
    {
        $initialRetriesCount = $retries;
        // Starting containers and running healthcheck may take quite long, especially in the multithread test

        while ($retries) {
            if ($this->httpClient->request('GET', $testUrl)->getStatusCode() === 200) {
                $this->logger->notice("$retries of $initialRetriesCount retries left to fetch $testUrl");

                return;
            }

            --$retries;
            sleep(1);
        }

        throw new \RuntimeException($errorMessage);
    }

    /**
     * @param string $projectRoot
     * @return void
     */
    protected function registerCleanupAsShutdownFunction(string $projectRoot): void
    {
        register_shutdown_function(\Closure::fromCallable([$this, 'cleanup']), $projectRoot);

        $signalRegistry = $this->getApplication()?->getSignalRegistry()
            ?? throw new \LogicException('Application is not initialized');
        $signalRegistry->register(
            SIGINT,
            function () use ($projectRoot) {
                $this->logger->notice('Process interrupted. Please, wait while cleanup is in progress...');
                // Cleanup called twice: once here and once in due to `register_shutdown_function`
                // This is expected, because we react to the signal, do not fail with exception,
                // and stop further execution. This last fact triggers shutdown and cleanup once more
                $this->cleanup($projectRoot);
                $this->skipCleanup = true;
                exit(0);
            }
        );
    }

    /**
     * Switch off composition and remove files even in case the process was terminated (CTRL + C)
     *
     * @param string $projectRoot
     * @return void
     */
    protected function cleanup(string $projectRoot): void
    {
        if ($this->skipCleanup) {
            return;
        }

        $this->logger->info('Trying to shut down composition...');

        foreach ($this->compositionCollection->getList($projectRoot) as $dockerCompose) {
            $dockerCompose->down();
        }

        if ($this->filesystem->isDir($projectRoot)) {
            // Works much faster than `$this->filesystem->remove([$projectRoot]);`. Fine for using in tests.
            $this->shell->mustRun("rm -rf $projectRoot");
        }

        $this->logger->info('Cleanup completed!');
    }

    /**
     * @param \Throwable $e
     * @param string $debugData
     * @return void
     */
    protected function logThrowable(\Throwable $e, string $debugData): void
    {
        $this->logger->emergency("FAILED! $debugData");
        // Render exception and write it to the log file with backtrace
        $output = new BufferedOutput();
        $output->setVerbosity($output::VERBOSITY_VERY_VERBOSE);

        if (!$this->getApplication()) {
            throw new \LogicException('Application is not initialized');
        }

        $this->getApplication()->renderThrowable($e, $output);
        $this->logger->emergency($output->fetch());
    }
}
