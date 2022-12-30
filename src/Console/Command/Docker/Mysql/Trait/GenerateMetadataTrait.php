<?php

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait;

use DefaultValue\Dockerizer\Console\Command\Docker\Mysql\GenerateMetadata;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

trait GenerateMetadataTrait
{
    /**
     * @param string $mysqlContainerName
     * @param string $targetImage - passed only during testing by `docker:mysql:test-metadata`!
     * @return string
     * @throws ExceptionInterface
     */
    private function generateMetadata(string $mysqlContainerName, string $targetImage = ''): string
    {
        $metadataCommand = $this?->getApplication()?->find('docker:mysql:generate-metadata')
            ?? throw new \LogicException('Application is not initialized');
        $inputParameters = [
            'command' => 'docker:mysql:generate-metadata',
            GenerateMetadata::COMMAND_ARGUMENT_CONTAINER => $mysqlContainerName,
            '-n' => true,
            '-q' => true
        ];

        if ($targetImage) {
            $inputParameters['--target-image'] = $targetImage;
        }

        $input = new ArrayInput($inputParameters);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $metadataCommand->run($input, $output);

        return $output->fetch();
    }
}
