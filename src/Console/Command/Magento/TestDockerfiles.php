<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Magento;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier\TestDockerfile
    as TestDockerfileModifier;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class TestDockerfiles extends TestTemplates
{
    protected static $defaultName = 'magento:test-dockerfiles';

    /**
     * @TODO: There is yet no way to select random services from the template. Thus hardcoding this to save time
     *
     * @var array<string, array{
     *     'template': string,
     *     'services_combination': array{
     *         'required': string[],
     *         'optional': string[]
     *      }
     * }> $hardcodedInstallationParameters
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
                    ''
                ]
            ]
        ],
        // At some moment, 3GB memory limit became not enough for 2.4.2 :(
        '2.4.2' => [
            'template' => 'magento_2.4.2_apache',
            'services_combination' => [
                Service::TYPE_REQUIRED => [
                    'php_7_4_apache',
                    'mysql_8_0_persistent',
                    'elasticsearch_7_9_3_persistent'
                ],
                Service::TYPE_OPTIONAL => [
                    ''
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
                    ''
                ]
            ]
        ],
        '2.4.5' => [
            'template' => 'magento_2.4.5_apache',
            'services_combination' => [
                Service::TYPE_REQUIRED => [
                    'php_8_1_apache',
                    'mariadb_10_4_persistent',
                    'elasticsearch_7_17_5_persistent'
                ],
                Service::TYPE_OPTIONAL => [
                    ''
                ]
            ]
        ]
    ];

    /**
     * @param TestDockerfileModifier $testDockerfileModifier
     * @param \DefaultValue\Dockerizer\Platform\Magento $magento
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection
     * @param \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject
     * @param \DefaultValue\Dockerizer\Process\Multithread $multithread
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \Symfony\Component\HttpClient\CurlHttpClient $httpClient
     * @param string $dockerizerRootDir
     * @param string|null $name
     */
    public function __construct(
        private TestDockerfileModifier $testDockerfileModifier,
        \DefaultValue\Dockerizer\Platform\Magento $magento,
        \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection,
        private \DefaultValue\Dockerizer\Process\Multithread $multithread,
        \DefaultValue\Dockerizer\Docker\Compose\Collection $compositionCollection,
        \DefaultValue\Dockerizer\Platform\Magento\CreateProject $createProject,
        \DefaultValue\Dockerizer\Shell\Shell $shell,
        \Symfony\Component\HttpClient\CurlHttpClient $httpClient,
        string $dockerizerRootDir,
        string $name = null
    ) {
        parent::__construct(
            $magento,
            $templateCollection,
            $multithread,
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
        $this->setDescription('Test Magento templates')
            // phpcs:disable Generic.Files.LineLength
            ->setHelp(<<<'EOF'
                Internal use only!
                The command <info>%command.name%</info> tests Dockerfiles by installing Magento with custom Dockerfiles which we develop and support (currently these are PHP 7.4+ images).
                Development Dockerfile is still built based on the DockerHub image!
                EOF);
            // phpcs:enable Generic.Files.LineLength

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->testDockerfileModifier->setActive(true);
        $callbacks = [];

        foreach ($this->hardcodedInstallationParameters as $magentoVersion => $parameters) {
            $callbacks[] = $this->getMagentoInstallCallback(
                $magentoVersion,
                $parameters['template'],
                $parameters['services_combination'],
                \Closure::fromCallable([$this, 'afterInstallCallback'])
            );
        }

        $this->multithread->run($callbacks, $output, TestTemplates::MAGENTO_MEMORY_LIMIT_IN_GB, 6);

        return self::SUCCESS;
    }
}
