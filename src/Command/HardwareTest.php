<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HardwareTest extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var \App\Config\Env $env
     */
    private $env;

    /**
     * @var \App\Service\Shell $shell
     */
    private $shell;

    /**
     * Magento version to PHP version.
     * @var array $versionsToTest
     */
    private static $versionsToTest = [
        '2.0.18' => '5.6',
        '2.1.18' => '7.0',
        '2.2.11' => '7.1',
        '2.3.2' => '7.2',
        '2.3.3' => '7.3',
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
     * HardwareTest constructor.
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
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('hardware:test')
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> sets up Magento and perform a number of tasks to test environment:
- install Magento 2 (first install is to warm up Docker images because they aren't on the Dockerhub yet);
- commit Docker files;
- test Dockerizer's <fg=blue>env:add</fg=blue> - stop containers, dockerize with another domains, add env and up;
- run <fg=blue>sampledata:deploy</fg=blue>;
- run <fg=blue>setup:upgrade</fg=blue>;
- run <fg=blue>deploy:mode:set production</fg=blue>;
- run <fg=blue>setup:perf:generate-fixtures</fg=blue> to generate data for performance testing (medium size profile);
- run <fg=blue>indexer:reindex</fg=blue>.

Usage for hardware test and Dockerizer self-test (install all instances and ensure they work fine):

    <info>php bin/console %command.full_name%</info>

@TODO:
- render 5 pages for 20 times;
- generate CSS files for 10-20 times.

EOF);
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

        try {
            if ($this->parallel($output, [$this, 'buildImage'])) {
                $this->waitForChildren($output);
            }

            if ($this->parallel($output, [$this, 'runTests'])) {
                $this->waitForChildren($output);
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            $exitCode = 1;
        }

        return $exitCode;
    }

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
            $domain = 'hardware-test-' . str_replace('.', '-', $magentoVersion) . '.local';
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Error forking process');
            }

            // If no PID then this is a child process and we can do the stuff
            if (!$pid) {
                // Set log file for child process, run callback
                $this->logFile = $this->env->getProjectsRootDir() .
                    'dockerizer_for_php' . DIRECTORY_SEPARATOR .
                    'hardware_test_results' . DIRECTORY_SEPARATOR .
                    "{$this->logFilePrefix}_{$domain}.log";

                $callback($domain, $phpVersion);
                return false;
            }

            $this->childProcessPidByDomain[$domain] = $pid;
            $output->writeln("PID #<fg=blue>$pid</fg=blue>: <fg=blue>$domain</fg=blue>");
        }

        // Set log file for the main process
        $this->logFile = $this->env->getProjectsRootDir() .
            'dockerizer_for_php' . DIRECTORY_SEPARATOR .
            'hardware_test_results' . DIRECTORY_SEPARATOR .
            "{$this->logFilePrefix}_main.log";

        return true;
    }

    /**
     * @param string $domain
     * @param string $phpVersion
     * @throws \RuntimeException
     */
    private function buildImage(string $domain, string $phpVersion): void
    {
        $projectsDir = $this->env->getProjectsRootDir();
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $domain;

        if (is_dir($tmpDir)) {
            $this->shell->passthru("cd $tmpDir && docker-compose down 2>/dev/null && rm -rf $tmpDir", true);
        }

        $this->log("Start building image for PHP $phpVersion");
        $this->shell->exec(<<<BASH
            mkdir $tmpDir
            cd $tmpDir
            mkdir pub/
            php {$projectsDir}dockerizer_for_php/bin/console dockerize -n \
                --domains="$domain www.$domain" \
                --php=$phpVersion
            docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --force-recreate --build
            docker-compose -f docker-compose.yml -f docker-compose-prod.yml down
            rm -rf $tmpDir
        BASH);
        $this->log("Completed building image for PHP $phpVersion");
    }

    private function runTests(string $domain, string $phpVersion)
    {
//        shell_exec(<<< BASH
//            cd /misc/apps/hw-test-2211.local/
//            # commit and check that all files are without changes after dockerization
//            git add .gitignore .htaccess docker* var/log/ app/
//            git commit -m "Docker and Magento files after installation"
//            docker-compose -f docker-compose.yml -f docker-compose-prod.yml down
//            rm -rf docker*
//            php /misc/apps/dockerizer_for_php/bin/console dockerize --domains="hw-test-2211-2.local www.hw-test-2211-2.local" --php=7.1 -n
//            php /misc/apps/dockerizer_for_php/bin/console env:add staging --domains="hw-test-2211.local www.hw-test-2211.local"
//            docker-compose -f docker-compose.yml -f docker-compose-staging.yml up -d --force-recreate --build
//        BASH);
//
//        $this->execWithTimer('docker exec -it hw-test-2211.local php bin/magento sampledata:deploy');
//        $this->execWithTimer('docker exec -it hw-test-2211.local php bin/magento setup:upgrade');
//        $this->execWithTimer('docker exec -it hw-test-2211.local php bin/magento deploy:mode:set production');
//        // Generate fixtures and run upgrade
//        $this->execWithTimer('docker exec -it hw-test-2211.local php bin/magento setup:perf:generate-fixtures /var/www/html/setup/performance-toolkit/profiles/ce/medium.xml');
//        $this->execWithTimer('docker exec -it hw-test-2211.local php bin/magento indexer:reindex');
    }

// must save timers to array and output on __destruct so that it is easier to move them to google doc with test results
//    private function execWithTimer(string $command)
//    {
//        $start = microtime(true);
//        shell_exec($command);
//        $executionTime = microtime(true) - $start;
//        $this->totalTime += $executionTime;
//
//        file_put_contents('time-1.log', "Command: $command\n", FILE_APPEND);
//        file_put_contents('time-1.log', "Execution time: $executionTime\n", FILE_APPEND);
//        file_put_contents('time-1.log', "Total: {$this->totalTime}\n\n", FILE_APPEND);
//    }

    /**
     * @param OutputInterface $output
     */
    private function waitForChildren(OutputInterface $output): void
    {
        while (count($this->childProcessPidByDomain)) {
            foreach ($this->childProcessPidByDomain as $domain => $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                // If the process has already exited
                if ($result === -1 || $result > 0) {
                    unset($this->childProcessPidByDomain[$domain]);
                    $message = $this->getDateTime() . ': '
                        . "PID #<fg=blue>$pid</fg=blue> for domain <fg=blue>$domain</fg=blue> completed";
                    $output->writeln($message);
                }
            }

            sleep(1);
        }
    }

    /**
     * @param string $message
     */
    private function log(string $message): void
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
    private function getDateTime(): string
    {
        return date('Y-m-d_H:i:s');
    }
}
