<?php

namespace DefaultValue\Dockerizer\Console;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

trait CommandLoggerTrait
{
    use \Psr\Log\LoggerAwareTrait;

    /**
     * @param string $projectDir
     * @return void
     */
    private function initLogger(string $projectDir): void
    {
        $logFileName = str_replace([':', '-'], '_', $this->getName()) . '.log';
        $internalLogPath = 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $logFileName;
        $this->setLogger(new Logger(
            'log',
            [
                new StreamHandler($projectDir . $internalLogPath)
            ]
        ));
    }
}