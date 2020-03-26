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
     * @var array $failedDomains
     */
    private $failedDomains = [];

    /**
     * @var array $timeByCommands
     */
    private $timeByCommand = [];

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
                $this->waitForChildren($output, 'buildImage');
            }

            if ($this->parallel($output, [$this, 'runTests'])) {
                $this->waitForChildren($output, 'runTests');
            }
        } catch (\Exception $e) {
            $this->log('Exception: ' . $e->getMessage());
            $output->writeln("<fg=red>Exception: {$e->getMessage()}</fg=red>");
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
                $callback($domain, $phpVersion, $magentoVersion);
                exit(0);
            }

            $this->childProcessPidByDomain[$domain] = $pid;
            $output->writeln("PID #<fg=blue>$pid</fg=blue>: <fg=blue>$domain</fg=blue>");
        }

        // Set log file for the main process
        $this->logFile = $this->getLogFile('_main');

        return true;
    }

    /**
     * @param string $domain
     * @param string $phpVersion
     * @throws \RuntimeException
     */
    private function buildImage(string $domain, string $phpVersion): void
    {
        $projectRoot = $this->env->getProjectsRootDir() . DIRECTORY_SEPARATOR . $domain;

        if (is_dir($projectRoot)) {
            $this->shell->passthru(
                <<<BASH
                    docker-compose down 2>/dev/null
                    rm -rf $projectRoot
                BASH,
                true,
                $projectRoot
            );
        }

        $tmpProjectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $domain;

        if (is_dir($tmpProjectRoot)) {
            $this->shell->passthru(
                <<<BASH
                    docker-compose down 2>/dev/null
                    rm -rf $tmpProjectRoot
                BASH,
                true,
                $tmpProjectRoot
            );
        }

        $this->log("Start building image for PHP $phpVersion");
        $this->shell->passthru("mkdir $tmpProjectRoot");
        $this->shell->exec(
            <<<BASH
                mkdir pub/
                php {$this->getDockerizerPath()} dockerize -n \
                    --domains="$domain www.$domain" \
                    --php=$phpVersion
                docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --force-recreate --build
                docker-compose -f docker-compose.yml -f docker-compose-prod.yml down
                rm -rf $tmpProjectRoot
            BASH,
            $tmpProjectRoot
        );
        // @TODO: ensure xdebug is installed
        $this->log("Completed building image for PHP $phpVersion");
    }

    /**
     * @param string $domain
     * @param string $phpVersion
     * @param string $magentoVersion
     */
    private function runTests(string $domain, string $phpVersion, string $magentoVersion): void
    {
        $projectRoot = $this->env->getProjectsRootDir() . $domain;
        $malformedDomain = str_replace('.local', '-2.local', $domain);

        $this->execWithTimer(<<<BASH
            php {$this->getDockerizerPath()} setup:magento $magentoVersion \
                --domains="$domain www.$domain" --php=$phpVersion -nf
        BASH);

        $this->shell->exec(
            <<<BASH
                git add .gitignore .htaccess docker* var/log/ app/
                git commit -m "Docker and Magento files after installation" 2>/dev/null
                docker-compose -f docker-compose.yml -f docker-compose-prod.yml down
                rm -rf docker*
                php {$this->getDockerizerPath()} dockerize -n \
                    --domains="$malformedDomain www.$malformedDomain" \
                    --php=$phpVersion
                php {$this->getDockerizerPath()} env:add staging --domains="$domain www.$domain" -f
                docker-compose -f docker-compose.yml -f docker-compose-staging.yml up -d --force-recreate --build
            BASH,
            $projectRoot
        );

        // Wait till Traefik starts proxying this host
        $retries = 10;
        $traefikBackend = str_replace('.', '', $domain);

        while ($retries) {
            $backendList = file_get_contents('http://localhost:8080/api/providers/docker/backends');

            if (strpos($backendList, $traefikBackend) === false) {
                --$retries;
                sleep(1);
            } else {
                break;
            }
        }

        $content = strtolower(file_get_contents("https://$domain"));

        if (strpos($content, 'home page') === false) {
            throw new \RuntimeException('Composition is not running!');
        }

        // We've changed main domain and added staging env, so here is the current container name:
        $containerName = "$malformedDomain-staging";

//        $this->execWithTimer("docker exec -it $containerName php bin/magento sampledata:deploy");
        $this->execWithTimer("docker exec -it $containerName php bin/magento setup:upgrade");
//        $this->execWithTimer("docker exec -it $containerName php bin/magento deploy:mode:set production");
//        // Generate fixtures and run upgrade
//        $this->execWithTimer(
//            "docker exec -it $containerName php bin/magento setup:perf:generate-fixtures" .
//            ' /var/www/html/setup/performance-toolkit/profiles/ce/medium.xml'
//        );
//        $this->execWithTimer("docker exec -it $containerName php bin/magento indexer:reindex");
        // @TODO: add test to curl pages; add tests to build less files
        $this->log("Website address: https://$domain");
    }

    /**
     * @param string $command
     */
    private function execWithTimer(string $command): void
    {
        $start = microtime(true);
        $this->shell->exec($command);
        $executionTime = microtime(true) - $start;
        $this->timeByCommand[$command] = $executionTime;
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
                    $message = $this->getDateTime() . ': '
                        . "PID #<fg=blue>$pid</fg=blue> running <fg=blue>$callbackMethodName</fg=blue> " .
                        "for website <fg=blue>https://$domain</fg=blue> completed";
                    $output->writeln($message);
                }

                if ($status !== 0) {
                    $this->failedDomains[] = $domain;
                    $output->writeln(
                        "<fg=red>Execution failed for domain</fg=red> <fg=blue>https://$domain</fg=blue>"
                    );
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

    /**
     * @return string
     */
    private function getDockerizerPath(): string
    {
        return $this->env->getProjectsRootDir() .
            'dockerizer_for_php' . DIRECTORY_SEPARATOR .
            'bin' . DIRECTORY_SEPARATOR .
            'console ';
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
            'hardware_test_results' . DIRECTORY_SEPARATOR .
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
                $commands[] = trim(str_replace(["\\\n", '  '], ' ', $command));
            }

            $this->log("\nExecuted commands:\n" . implode("\n", $commands));
            $this->log("\nTiming per command:\n" . implode("\n", $this->timeByCommand));
            $this->log("\nTotal:\n" . array_sum($this->timeByCommand));
        }
    }
}
