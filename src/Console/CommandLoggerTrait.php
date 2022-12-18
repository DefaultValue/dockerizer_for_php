<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

trait CommandLoggerTrait
{
    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @param string $projectDir
     * @return void
     */
    protected function initLogger(string $projectDir): void
    {
        if (isset($this->logger)) {
            throw new \LogicException('Logger already initialized');
        }

        $logFileName = str_replace([':', '-'], '_', $this->getName()) . '.log';
        $internalLogPath = 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $logFileName;
        // Always set a unique name to be able to find logs related to every unique command
        // Useful for multithreading and for running the same command in multiple terminals
        $handler = new StreamHandler($projectDir . $internalLogPath);
        $formatter = $handler->getFormatter();

        if ($formatter instanceof LineFormatter) {
            $formatter->allowInlineLineBreaks();
        }

        $this->logger = new Logger(uniqid('', false), [$handler]);
    }
}
