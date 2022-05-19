<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Process;

class Multithread
{
    private array $childProcessPIDs = [];

    /**
     * @param callable[] $callbacks
     * @param int $maxThreads - to be used when command(s) have this parameter
     * @return void
     */
    public function run(array $callbacks, int $maxThreads = 4): void
    {
        $maxThreads = $this->getMaxThreads($maxThreads);
        $maxThreads = 5;

        while ($callbacks) {
            $callback = array_shift($callbacks);

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Error forking process');
            }

            // If there is no PID then this is a child process, and we can do the stuff
            if (!$pid) {
                try {
                    $callback();
                } catch (\Exception) {
                    exit(1);
                }

                exit(0);
            }

            // If there is PID then we're in the parent process
            $this->childProcessPIDs[$pid] = true;

            // Continue if we can handle more threads
            if (count($this->childProcessPIDs) < $maxThreads) {
                continue;
            }

            $this->waitForAtLeastOneChildToComplete($maxThreads);
        }

        $this->waitForAtLeastOneChildToComplete(0);
    }

    private function getMaxThreads(int $maxThreads): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuInfo, $matches);
            $maxThreads = count($matches[0]) - 1;
        }

        return $maxThreads;
    }

    private function waitForAtLeastOneChildToComplete(int $maxThreads): void
    {
        while (count($this->childProcessPIDs) && count($this->childProcessPIDs) >= $maxThreads) {
            foreach (array_keys($this->childProcessPIDs) as $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                // If the process has already exited
                if ($result === -1 || $result > 0) {
                    unset($this->childProcessPIDs[$pid]);
//                    $message = $this->getDateTime() . ': '
//                        . "PID #<fg=blue>$pid</fg=blue> completed <fg=blue>$callbackMethodName</fg=blue> "
//                        . "for the website <fg=blue>https://$domain</fg=blue>";
//                    $output->writeln($message);
//                    echo "Completed!";

                    if ($status !== 0) {
//                        $this->failedDomains[] = $domain;
//                        $output->writeln(
//                            "<fg=red>Execution failed for the domain</fg=red> <fg=blue>https://$domain</fg=blue>"
//                        );
//                        $output->writeln("<fg=red>Status:</fg=red> <fg=blue>$status</fg=blue>");
//                        echo "Failed!";
                    }
                }
            }

            sleep(1);
        }
    }
}