<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetContainerName extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:get-container-name';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setDescription('Get Docker container name by service name in docker-compose*.yaml')
            ->addArgument(
                'service-name',
                InputArgument::REQUIRED,
                'Service name'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to docker-compose files'
            )
            ->setHelp(<<<'EOF'
                Run <info>%command.name%</info> return Docker container name for the given running service
                within any composition. This is especially useful for creating shell aliases.

                Simple usage:

                    <info>php %command.full_name% php</info>
                EOF);

        parent::configure();
    }

    /**
     * @param ArgvInput $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = $input->getArgument('service-name');
        $pathToDockerComposeFiles = $input->getArgument('path') ?: getcwd();
        $dockerCompose = $this->dockerCompose->initialize($pathToDockerComposeFiles);
        $containerName = $dockerCompose->getServiceContainerName($service);

        // Set normal verbosity to output result
        $output->setVerbosity($output::VERBOSITY_NORMAL);
        $output->write($containerName);

        return self::SUCCESS;
    }
}
