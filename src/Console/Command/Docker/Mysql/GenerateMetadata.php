<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class GenerateMetadata extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'docker:mysql:generate-metadata';

    private const COMMAND_ARGUMENT_CONTAINER_NAME = 'container-name';

    public const REGISTRY_DOMAIN = 'REGISTRY_DOMAIN';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Docker $docker
     * @param \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Docker $docker,
        private \DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql $mysql,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        parent::configure();

        // phpcs:disable Generic.Files.LineLength
        $this->setHelp(<<<'EOF'
            Generate DB metadata file for a given container. This metadata can be used to reconstruct the same container. For example, this can be useful to build DB images with CI/CD tools.

                <info>php %command.full_name% <container-name></info>
            EOF)
            ->addArgument(
                self::COMMAND_ARGUMENT_CONTAINER_NAME,
                InputArgument::REQUIRED,
                'Docker container name'
            );
        // phpcs:enable
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dockerContainerName = $input->getArgument(self::COMMAND_ARGUMENT_CONTAINER_NAME);
        $containerMetadata = $this->docker->containerInspect($dockerContainerName);
        $mysql = $this->mysql->initialize($dockerContainerName);

        $databaseMetadata = [
            MysqlMetadataKeys::DB_TYPE => $mysql->getDbType(),

        ];

        // save the file
        $output->setVerbosity($output::VERBOSITY_NORMAL);
        $output->write(json_encode($databaseMetadata));

        return self::SUCCESS;
    }

    private function getDbVersoipn(array $containerMetadata)
    {}

    private function getEnvironment(array $containerMetadata)
    {}

    private function getMyCnf(array $containerMetadata)
    {
    }

    private function getTargetRepository(array $containerMetadata)
    {
        // registry domain + repository || ask to provide/confirm repository
    }
}
