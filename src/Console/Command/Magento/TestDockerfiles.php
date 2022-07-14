<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Console\Command\Composition\BuildFromTemplate;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Domains as CommandOptionDomains;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Force as CommandOptionForce;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestDockerfiles extends AbstractTestCommand
{
    protected static $defaultName = 'magento:test-dockerfiles';

    private const DOCKERFILES_PATH = '';

    /**
     * @TODO: There is yet no way to select random services from the template. Thus hardcoding this to save time
     *
     * @var array $hardcodedInstallationParameters
     */
    private $hardcodedInstallationParameters = [

    ];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private string $dockerizerRootDir,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $shell,
            $httpClient,
            $dockerizerRootDir,
            $name
        );
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('<info>Test Magento templates</info>')
            // phpcs:disable Generic.Files.LineLength
            ->setHelp(<<<'EOF'
                Internal use only!
                The command <info>%command.name%</info> tests Magento templates by installing them with custom Dockerfiles which we develop and support (currently these are PHP 7.4+ images)
                EOF);
            // phpcs:enable Generic.Files.LineLength

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $callbacks = [];

        foreach ($this->hardcodedInstallationParameters as $domain => $parameters) {
            $callbacks[] = $this->getCallback($domain, $parameters);
        }

        //$this->multithread->run($callbacks, $output, TestTemplates::MAGENTO_MEMORY_LIMIT_IN_GB, 6);

        return self::SUCCESS;
    }

    public function getCallback(string $domain, array $parameters): \Closure
    {
        return function () use ($domain) {
            // Reinit logger to have individual name for every callback
            // This way we can identify logs for every callback
            $this->initLogger($this->dockerizerRootDir, uniqid('', false));
            $testUrl = "https://$domain/";
            $environment = array_rand(['dev' => true, 'prod' => true, 'staging' => true]);
            $projectRoot = $this->createProject->getProjectRoot($domain);
            register_shutdown_function(\Closure::fromCallable([$this, 'cleanUp']), $projectRoot);

            // Set dump callback here?
            $this->composition->addAfterDumpCallback(\Closure::fromCallable([$this, 'afterDumpCallback']));


            $this->buildCompositionFromTemplate(
                $input,
                $output,
                [
                    'command' => 'composition:build-from-template',
                    '--' . CommandOptionDomains::OPTION_NAME => $domains,
                    '--' . BuildFromTemplate::OPTION_PATH => $projectRoot,
                    '--' . BuildFromTemplate::OPTION_DUMP => false
                ]
            );
            $force = $this->getCommandSpecificOptionValue($input, $output, CommandOptionForce::OPTION_NAME);

            // Install Magento
            $this->createProject->createProject($output, $magentoVersion, $domains, $force);
            $output->writeln('Docker container should be ready. Trying to install Magento...');
            // CWD is changed while creating project, so setup happens in the project root dir
            $this->setupInstall->setupInstall(
                $output,
                array_values($this->compositionCollection->getList($projectRoot))[0]
            );
            $output->writeln('Magento installation completed!');


            $initialPath =  array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), -1)[0]['file'];
            $command = "php $initialPath magento:setup";
            $command .= " $magentoVersion";
            $command .= " --with-environment=$environment";
            $command .= " --domains='$domain www.$domain'";
            $command .= ' --template=' . $templateCode;
            $command .= " --required-services='$requiredServices'";
            $command .= " --optional-services='$optionalServices'";
            $command .= ' -n -f -q';


        };
    }

    private function afterDumpCallback($modificationContext)
    {
        $foo = false;
    }
}
