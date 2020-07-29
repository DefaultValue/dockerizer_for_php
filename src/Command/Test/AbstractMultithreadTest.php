<?php

declare(strict_types=1);

namespace App\Command\Test;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractMultithreadTest extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var \App\Config\Env $env
     */
    protected $env;

    /**
     * @var \App\Service\Shell $shell
     */
    protected $shell;

    /**
     * Magento version to PHP version.
     * @var array $versionsToTest
     */
    private static $versionsToTest = [
        '2.0.18' => '5.6',
        '2.1.18' => '7.0',
        '2.2.11' => '7.1',
        '2.3.2'  => '7.2',
        '2.3.5'  => '7.3'
    ];

    /**
     * @var string $logFilePrefix
     */
    private $logFilePrefix = '';

    /**
     * @var string $logFile
     */
    private $logFile = '';

    /**
     * @var array $childProcessPidByDomain
     */
    private $childProcessPidByDomain = [];

    /**
     * @var array $failedDomains
     */
    private $failedDomains = [];

    /**
     * @var array $timeByCommands
     */
    private $timeByCommand = [];

    /**
     * @var string $domainAndLogFilePrefix
     */
    private $domainAndLogFilePrefix = '';

    /**
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param string|null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        string $name = null
    ) {
        parent::__construct($name);
        $this->env = $env;
        $this->shell = $shell;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $exitCode = 0;
        $this->logFilePrefix = $this->getDateTime();
        $this->domainAndLogFilePrefix = strtolower((new \ReflectionClass($this))->getShortName());

        try {
            foreach ($this->getCallbacks() as $callback) {
                if ($this->parallel($output, $callback)) {
                    $this->waitForChildren($output, $callback[1]);
                }
            }

            foreach (self::$versionsToTest as $magentoVersion => $phpVersion) {
                $domain = $this->domainAndLogFilePrefix . '-test-' . str_replace('.', '-', $magentoVersion) . '.local';

                if (!in_array($domain, $this->failedDomains, true)) {
                    $output->writeln("Success: <fg=blue>https://$domain/</fg=blue>");
                }
            }
        } catch (\Exception $e) {
            $this->log('Exception: ' . $e->getMessage());
            $output->writeln("<fg=red>Exception: {$e->getMessage()}</fg=red>");
            $exitCode = 1;
        }

        return $exitCode;
    }

    /**
     * Get a list of callbacks to run in parallel
     *
     * @return callable[]
     */
    abstract protected function getCallbacks(): array;

    /**
     * @param OutputInterface $output
     * @param callable $callback
     * @return bool
     * @throws \RuntimeException
     */
    private function parallel(OutputInterface $output, callable $callback): bool
    {
        // Stage 1: warm up all images to ensure this does not affect test results and installation of all instances
        // starts at the same time
        foreach (self::$versionsToTest as $magentoVersion => $phpVersion) {
            $domain = $this->domainAndLogFilePrefix . '-test-' . str_replace('.', '-', $magentoVersion) . '.local';

            if (in_array($domain, $this->failedDomains, true)) {
                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Error forking process');
            }

            // If no PID then this is a child process and we can do the stuff
            if (!$pid) {
                // Set log file for child process, run callback
                $this->logFile = $this->getLogFile($domain);

                try {
                    $callback($domain, $phpVersion, $magentoVersion);
                } catch (\Exception $e) {
                    $this->log('Exception: ' . $e->getMessage());
                    exit(1);
                }

                exit(0);
            }

            $this->childProcessPidByDomain[$domain] = $pid;
            $output->writeln($this->getDateTime() . ": PID #<fg=blue>$pid</fg=blue>: <fg=blue>$domain</fg=blue>");
        }

        // Set log file for the main process
        $this->logFile = $this->getLogFile('_main');

        return true;
    }

    /**
     * @param OutputInterface $output
     * @param string $callbackMethodName
     */
    private function waitForChildren(OutputInterface $output, string $callbackMethodName): void
    {
        while (count($this->childProcessPidByDomain)) {
            foreach ($this->childProcessPidByDomain as $domain => $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                // If the process has already exited
                if ($result === -1 || $result > 0) {
                    unset($this->childProcessPidByDomain[$domain]);
                    $message = $this->getDateTime() . ': ' .
                        "PID #<fg=blue>$pid</fg=blue> running <fg=blue>$callbackMethodName</fg=blue> " .
                        "for website <fg=blue>https://$domain</fg=blue> completed";
                    $output->writeln($message);

                    if ($status !== 0) {
                        $this->failedDomains[] = $domain;
                        $output->writeln(
                            "<fg=red>Execution failed for the domain</fg=red> <fg=blue>https://$domain</fg=blue>"
                        );
                        $output->writeln("<fg=red>Status:</fg=red> <fg=blue>$status</fg=blue>");
                    }
                }
            }

            sleep(1);
        }
    }

    /**
     * @param string $command
     * @return void
     */
    protected function execWithTimer(string $command): void
    {
        $start = microtime(true);
        // Using ::exec() to suppress output
        $this->shell->exec($command);
        $executionTime = microtime(true) - $start;
        $this->timeByCommand[$command] = $executionTime;
    }

    /**
     * @param string $message
     */
    protected function log(string $message): void
    {
        file_put_contents(
            $this->logFile,
            "{$this->getDateTime()}: $message\n",
            FILE_APPEND
        );
    }

    /**
     * @return string
     */
    protected function getDockerizerPath(): string
    {
        return $this->env->getProjectsRootDir() .
            'dockerizer_for_php' . DIRECTORY_SEPARATOR .
            'bin' . DIRECTORY_SEPARATOR .
            'console ';
    }

    /**
     * @return string
     */
    private function getDateTime(): string
    {
        return date('Y-m-d_H:i:s');
    }

    /**
     * @param string $domain
     * @return string
     */
    private function getLogFile(string $domain): string
    {
        return $this->env->getProjectsRootDir() .
            'dockerizer_for_php' . DIRECTORY_SEPARATOR .
            'var' . DIRECTORY_SEPARATOR .
            'log' . DIRECTORY_SEPARATOR .
            "{$this->logFilePrefix}_{$domain}.log";
    }

    /**
     * Write collected timings to the log file in columns, so that it is easy to copy them to the Google Sheet
     */
    public function __destruct()
    {
        if (count($this->timeByCommand)) {
            $commands = [];

            foreach (array_keys($this->timeByCommand) as $command) {
                $command = str_replace("\\\n", '', $command);
                $commands[] = trim(preg_replace('/\s+/', ' ', $command));
            }

            $this->log("\nExecuted commands:\n" . implode("\n", $commands));
            $this->log("\nTiming per command:\n" . implode("\n", $this->timeByCommand));
            $this->log("\nTotal:\n" . array_sum($this->timeByCommand));
        }
    }
}
