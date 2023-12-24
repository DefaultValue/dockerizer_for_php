<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use DefaultValue\Dockerizer\Shell\Shell;
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
        register_shutdown_function(\Closure::fromCallable([$this, 'cleanup']), $projectRoot, true);

        $signalRegistry = $this->getApplication()?->getSignalRegistry()
            ?? throw new \LogicException('Application is not initialized');
        $signalRegistry->register(
            SIGINT,
            function () use ($projectRoot) {
                // Cleanup called twice: once here and once due to `register_shutdown_function`.
                // Run cleanup only if it hasn't been called from the `register_shutdown_function` yet
                // (e.g., this isn't a shutdown sequence yet).
                if (!$this->skipCleanup) {
                    $this->logger->notice('Process interrupted. Please, wait while cleanup is in progress...');
                    $this->cleanup($projectRoot, true);

                    exit(self::SUCCESS);
                }
            }
        );
    }

    /**
     * Switch off composition and remove files even in case the process was terminated (CTRL + C)
     *
     * @param string $projectRoot
     * @param bool $isFinalCleanup - Allow to use this method in the middle of the test
     * @return void
     * @throws \Throwable
     */
    protected function cleanup(string $projectRoot, bool $isFinalCleanup = false): void
    {
        if ($this->skipCleanup) {
            return;
        }

        if ($isFinalCleanup) {
            $this->skipCleanup = true;
        }

        $this->logger->info('Trying to shut down composition...');
        $start = microtime(true);

        try {
            foreach ($this->compositionCollection->getList($projectRoot) as $dockerCompose) {
                $dockerCompose->down();
            }

            if ($this->filesystem->isDir($projectRoot)) {
                // Works much faster than `$this->filesystem->remove([$projectRoot]);`. Fine for using in tests.
                // But still fails under massive load. Thus, must use quite high timeout.
                $this->shell->mustRun("rm -rf $projectRoot", null, [], null, Shell::EXECUTION_TIMEOUT_MEDIUM);
            }

            $this->logger->info("Cleaning up {$this->filesystem->getHostsFilePath()}...");
            $domainName = basename($projectRoot);
            $hostsFileContent = [];
            $hostsFileLines = explode(
                PHP_EOL,
                $this->filesystem->fileGetContents($this->filesystem->getHostsFilePath())
            );

            foreach ($hostsFileLines as $hostsLine) {
                if (
                    str_starts_with($hostsLine, '127.0.0.1')
                    && (str_contains($hostsLine, " $domainName ") || str_contains($hostsLine, "-$domainName"))
                ) {
                    continue;
                }

                $hostsFileContent[] = $hostsLine;
            }

            // The worst that can happen is that some other thread will write to the file at the same time.
            // This isn't a big issues, so no need to use `flock` here.
            $this->filesystem->filePutContents(
                $this->filesystem->getHostsFilePath(),
                implode(PHP_EOL, $hostsFileContent)
            );

            // What about cleaning up SSL certificates?
        } catch (\Throwable $e) {
            $this->logThrowable($e, sprintf('Cleanup failed after %ds!', microtime(true) - $start));

            throw $e;
        }

        $this->logger->info(sprintf('Cleanup completed in %ds!', microtime(true) - $start));
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
