<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Process;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;

/**
 * Currently linux hosts only, because we check available CPU and memory. Need implementation for other OSes
 */
class Multithread
{
    /**
     * @var array<int, float>
     */
    private array $childProcessPIDs = [];

    private bool $terminate = false;

    /**
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Shell $shell
    ) {
    }

    /**
     * @param callable[] $callbacks
     * @param OutputInterface $output
     * @param SignalRegistry $signalRegistry
     * @param float $memoryRequirementsInGB
     * @param int $maxThreads
     * @param int $startDelay - delay starting new processes to eliminate shock from to many threads started at once
     * @return void
     */
    public function run(
        array $callbacks,
        OutputInterface $output,
        SignalRegistry $signalRegistry,
        float $memoryRequirementsInGB = 0.5,
        int $maxThreads = 4,
        int $startDelay = 10,
    ): void {
        $maxThreads = $this->getMaxThreads($maxThreads, $memoryRequirementsInGB);
        $totalCallbacks = count($callbacks);
        $output->writeln(sprintf(
            // phpcs:disable Generic.Files.LineLength.TooLong
            'Processing %d callbacks in max %d threads (%.2fGB RAM per thread) with %ds delay before starting a new thread',
            // phpcs:enable
            $totalCallbacks,
            $maxThreads,
            $memoryRequirementsInGB,
            $startDelay
        ));

        // Send kill signal to all child processes for proper tier down
        // Need to check Process::doSignal() for more info about this and `enable-sigchild`
        $signalRegistry->register(
            SIGINT,
            function () use ($output) {
                $this->terminate = true;

                if (!count($this->childProcessPIDs)) {
                    return;
                }

                foreach (array_keys($this->childProcessPIDs) as $pid) {
                    $output->writeln("Sending SIGINT to the process <info>#$pid</info>...");
                    posix_kill($pid, SIGINT);
                }

                $output->writeln('Please, wait for the child processes to complete...');
            }
        );

        $callbackNumber = 0;

        // Handle callbacks, stop if SIGINT was received
        while ($callbacks && !$this->terminate) {
            $callback = array_shift($callbacks);
            $lastStart = microtime(true);

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Error forking process');
            }

            // If there is no PID then this is a child process, and we can do the stuff
            if (!$pid) {
                try {
                    // Child process MUST NOT know about other process PIDs and do not handle their shutdown
                    $this->childProcessPIDs = [];
                    $callback();
                } catch (\Throwable) {
                    exit(1);
                }

                exit(0);
            }

            // If there is PID then we're in the parent process
            $this->childProcessPIDs[$pid] = microtime(true);
            ++$callbackNumber;
            $message = sprintf(
                '%s: Started new process with ID #<fg=blue>%d</fg=blue> (%d/%d)',
                $this->getDateTime(),
                $pid,
                $callbackNumber,
                $totalCallbacks
            );
            $output->writeln($message);

            if (
                $startDelay
                && (microtime(true) - $lastStart < $startDelay)
            ) {
                $sleepTime = (int) ceil($startDelay - (microtime(true) - $lastStart));

                while ($callbacks && $sleepTime--) {
                    $this->checkChildProcesses($output);
                    sleep(1);
                }

                $this->checkChildProcesses($output);
            }

            $this->waitForAtLeastOneChildToComplete($maxThreads, $output);
        }

        $this->waitForAtLeastOneChildToComplete(0, $output);
    }

    /**
     * @param int $maxThreads
     * @param float $memoryRequirementsInGB
     * @return int
     */
    private function getMaxThreads(int $maxThreads, float $memoryRequirementsInGB): int
    {
        $process = $this->shell->mustRun('grep cpu.cores /proc/cpuinfo | sort -u');
        $output = trim($process->getOutput());
        $coresCount = (int) strrev($output);

        $availableMemoryInGb = 0;
        $process = $this->shell->mustRun('grep MemAvailable /proc/meminfo');
        $output = trim($process->getOutput());

        if (preg_match('/^MemAvailable:\s+(\d+)\skB$/', $output, $pieces)) {
            $availableMemoryInGb = ((int) $pieces[1]) / 1024 / 1024;
        }

        if (!$coresCount || !$availableMemoryInGb) {
            throw new \RuntimeException('Can\'t analyze memory or CPU params on this host ');
        }

        // Leave at least one core for other tasks
        return min($maxThreads, $coresCount - 1, (int) floor($availableMemoryInGb / $memoryRequirementsInGB));
    }

    /**
     * @param int $maxThreads
     * @param OutputInterface $output
     * @return void
     */
    private function waitForAtLeastOneChildToComplete(int $maxThreads, OutputInterface $output): void
    {
        while (count($this->childProcessPIDs) && count($this->childProcessPIDs) >= $maxThreads) {
            $this->checkChildProcesses($output);
            sleep(1);
        }
    }

    /**
     * @param OutputInterface $output
     * @return void
     */
    private function checkChildProcesses(OutputInterface $output): void
    {
        foreach (array_keys($this->childProcessPIDs) as $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            // If the process has already exited
            if ($result === -1 || $result > 0) {
                $message = $status === 0
                    ? '%s: PID #<fg=blue>%d</fg=blue> completed in %ds'
                    : '%s: PID #<fg=blue>%d</fg=blue> <fg=red>failed</fg=red> in %ds with status <fg=red>%s</fg=red>! Check log file.';

                $output->writeln(sprintf(
                    $message,
                    $this->getDateTime(),
                    $pid,
                    microtime(true) - $this->childProcessPIDs[$pid],
                    $status
                ));
                unset($this->childProcessPIDs[$pid]);
            }
        }
    }

    /**
     * @return string
     */
    private function getDateTime(): string
    {
        return date('Y-m-d_H:i:s');
    }
}
