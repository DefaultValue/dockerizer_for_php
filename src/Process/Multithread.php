<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Process;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Currently linux hosts only, because we check available CPU and memory. Need implementation for other OSes
 */
class Multithread
{
    private array $childProcessPIDs = [];

    private bool $terminate = false;

    /**
     * @param array $callbacks
     * @param OutputInterface $output
     * @param float $memoryRequirementsInGB
     * @param int $maxThreads
     * @return void
     */
    public function run(
        array $callbacks,
        OutputInterface $output,
        float $memoryRequirementsInGB = 0.5,
        int $maxThreads = 4
    ): void {
        $maxThreads = $this->getMaxThreads($maxThreads, $memoryRequirementsInGB);

        // Send kill signal to all child processes for proper tier down
        pcntl_signal(SIGINT, function () use ($output) {
            if (!count($this->childProcessPIDs)) {
                return;
            }

            $output->writeln('Sending SIGINT to the child processes and waiting for them to complete...');

            foreach (array_keys($this->childProcessPIDs) as $pid) {
                posix_kill($pid, SIGINT);
            }

            $this->terminate = true;
        });

        // Handle callbacks, stop if SIGINT was received
        while ($callbacks && !$this->terminate) {
            $callback = array_shift($callbacks);

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
                } catch (\Exception) {
                    exit(1);
                }

                exit(0);
            }

            // If there is PID then we're in the parent process
            $this->childProcessPIDs[$pid] = microtime(true);
            $message = sprintf(
                '%s: Started new process with ID #<fg=blue>%d</fg=blue>',
                $this->getDateTime(),
                $pid,
            );
            $output->writeln($message);

            // Continue if we can handle more threads
            if (count($this->childProcessPIDs) < $maxThreads) {
                continue;
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
        $coresCount = 0;

        if (is_file('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuInfo, $matches);
            $coresCount = count($matches[0]);
        }

        $fh = fopen('/proc/meminfo', 'rb');
        $availableMemoryInGb = 0;

        while ($line = fgets($fh)) {
            $pieces = array();
            if (preg_match('/^MemAvailable:\s+(\d+)\skB$/', $line, $pieces)) {
                $availableMemoryInGb = $pieces[1] / 1024 / 1024;
                break;
            }
        }

        fclose($fh);

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
            foreach (array_keys($this->childProcessPIDs) as $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                // If the process has already exited
                if ($result === -1 || $result > 0) {
                    $message = sprintf(
                        '%s: PID #<fg=blue>%d</fg=blue> completed in %ds',
                        $this->getDateTime(),
                        $pid,
                        microtime(true) - $this->childProcessPIDs[$pid]
                    );
                    $output->writeln($message);

                    if ($status !== 0) {
                        $output->writeln('<fg=red>Process execution failed!</fg=red> Check log file for more details.');
                    }

                    unset($this->childProcessPIDs[$pid]);
                }
            }

            sleep(1);
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
