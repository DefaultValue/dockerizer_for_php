<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @TODO: can make this command multithread in the future
 * Current implementation contains quite a lot of hardcode
 *
 * @noinspection PhpUnused
 */
class TestMetadata extends \Symfony\Component\Console\Command\Command implements
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    protected static $defaultName = 'docker:mysql:test-metadata';

    public const TEMPLATE_WITH_DATABASES = 'generic_php_apache_app';

    public const DOMAIN = 'test-metadata.local';



    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private string $dockerizerRootDir,
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
            Test the script that generates DB metadata files by running various containers, generating metadata and reconstructing them.
            The script to reconstruct DB is not public yet. Contact us if you're interested in building something like that.

                <info>php %command.full_name% <path-to-db-reconstructor></info>
            EOF);
        // phpcs:enable
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Template $template */
        $template = $this->templateCollection->getByCode(self::TEMPLATE_WITH_DATABASES);
        $domain = self::DOMAIN;
        $projectRoot = $this->env->getProjectsRootDir() . $domain;
        $dockerComposeRoot = $projectRoot . DIRECTORY_SEPARATOR . '.dockerizer/test-metadata.local-prod/';

        foreach (array_keys($template->getServices(Service::TYPE_OPTIONAL)['database']) as $database) {
            $command = <<<SHELL
                php {$this->dockerizerRootDir}bin/dockerizer composition:build-from-template -n -f \
                    --path='$projectRoot' \
                    --template='{$template->getCode()}' \
                    --domains='$domain' \
                    --required-services='php_8_1_apache' \
                    --optional-services='$database' \
                    --with-web_root=''
            SHELL;
            $this->shell->mustRun($command, $this->env->getProjectsRootDir());
            $dockerCompose = $this->dockerCompose->initialize($dockerComposeRoot);
            $dockerCompose->up();
            $metadata = $this->getMetadata();
            $dockerCompose->down();

            $output->writeln($metadata);
            $foo = false;
        }

        return self::SUCCESS;
    }

    /**
     * @return string
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     */
    private function getMetadata(): string
    {
        $metadataCommand = $this->getApplication()->find('docker:mysql:generate-metadata');
        $input = new ArrayInput([
            'command' => 'docker:mysql:generate-metadata',
            'container-name' => 'test-metadatalocal-prod_mysql_1',
            '-n' => true,
            '-q' => true
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $metadataCommand->run($input, $output);
        return $output->fetch();
    }
}
