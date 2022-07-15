<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier\TestDockerfile
    as TestDockerfileModifier;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TestDockerfiles extends AbstractTestCommand
{
    protected static $defaultName = 'magento:test-dockerfiles';

    /**
     * @TODO: There is yet no way to select random services from the template. Thus hardcoding this to save time
     *
     * @var array $hardcodedInstallationParameters
     */
    private array $hardcodedInstallationParameters = [
        '2.3.7-p3' => [
            'template' => 'magento_2.3.7-p3_apache',
            'services_combination' => [
                Service::TYPE_REQUIRED => [
                    'php_7_4_apache',
                    'mysql_5_7_persistent'
                ],
                Service::TYPE_OPTIONAL => [
                    'redis_6_0'
                ]
            ]
        ],
        '2.4.4' => [
            'template' => 'magento_2.4.4_apache',
            'services_combination' => [
                Service::TYPE_REQUIRED => [
                    'php_8_1_apache',
                    'mariadb_10_4_persistent',
                    'elasticsearch_7_16_3_persistent'
                ],
                Service::TYPE_OPTIONAL => [
                    'redis_6_2'
                ]
            ]
        ]
    ];

    /**
     * @param TestDockerfileModifier $testDockerfileModifier
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private TestDockerfileModifier $testDockerfileModifier,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $compositionCollection,
            $createProject,
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
        $this->testDockerfileModifier->setActive(true);
        $callbacks = [];

        foreach ($this->hardcodedInstallationParameters as $magentoVersion => $parameters) {
            $callbacks[] = $this->getCallback($magentoVersion, $parameters);
        }

        $this->multithread->run($callbacks, $output, TestTemplates::MAGENTO_MEMORY_LIMIT_IN_GB, 6);

        return self::SUCCESS;
    }

    /**
     * @param string $magentoVersion
     * @param array $parameters
     * @return \Closure
     */
    public function getCallback(string $magentoVersion, array $parameters): \Closure
    {
        $afterInstallCallback = function (string $domain) {
            $dockerCompose = $this->dockerCompose->initialize($this->testDockerfileModifier->getDockerComposeDir());
            $this->logger->info('Restart composition with dev tools');
            $dockerCompose->down(false);
            $dockerCompose->up();

            if ($this->getStatusCode("https://$domain/") !== 200) {
                throw new \RuntimeException('Can\'t start composition with dev tools!');
            }

            $this->logger->info('Reinstall Magento');
            $reinstallCommand = $this->getApplication()->find('magento:reinstall');
            chdir(dirname($this->testDockerfileModifier->getDockerComposeDir(), 2));
            $input = new ArrayInput([
                '-n' => true,
                '-q' => true
            ]);
            $input->setInteractive(false);
            $reinstallCommand->run($input, new NullOutput());
        };

        return $this->getMagentoInstallCallback(
            $magentoVersion,
            $parameters['template'],
            $parameters['services_combination'],
            $afterInstallCallback
        );
    }
}
