<?php

namespace DefaultValue\Dockerizer\Console;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

trait CommandLoggerTrait
{
    use \Psr\Log\LoggerAwareTrait;

    /**
     * @param string $projectDir
     * @param string $name
     * @return void
     */
    protected function initLogger(string $projectDir, string $name = 'log'): void
    {
        $logFileName = str_replace([':', '-'], '_', $this->getName()) . '.log';
        $internalLogPath = 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $logFileName;
        $this->setLogger(new Logger(
            $name,
            [
                new StreamHandler($projectDir . $internalLogPath)
            ]
        ));
    }
}