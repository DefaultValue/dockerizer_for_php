<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

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
     * @param string $dockerizerRootDir
     * @return void
     */
    protected function initLogger(string $dockerizerRootDir): void
    {
        if (isset($this->logger)) {
            throw new \LogicException('Logger already initialized');
        }

        $logFileName = str_replace([':', '-'], '_', $this->getName()) . '.log';
        $internalLogPath = 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $logFileName;
        // Always set a unique name to be able to find logs related to every unique command
        // Useful for multithreading and for running the same command in multiple terminals
        $handler = new StreamHandler($dockerizerRootDir . $internalLogPath);
        $formatter = $handler->getFormatter();

        if ($formatter instanceof LineFormatter) {
            $formatter->allowInlineLineBreaks();
        }

        // Log PID to be able to find logs related to every thread, because Multithread class works with child PIDs
        // Add unique ID in case PID is not unique
        $this->logger = new Logger(
            sprintf('pid-%d.uid-%s', getmypid(), uniqid('', true)),
            [$handler]
        );
    }
}
