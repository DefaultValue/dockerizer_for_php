<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;
use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierCollection;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A STATEFUL container for generating a new composition and assembling composition files.
 * This IS a singleton that keeps its state during a single application run
 */
class Composition
{
    private const DOCKER_COMPOSE_FILE = 'docker-compose.yaml';

    private const DOCKER_COMPOSE_DEV_TOOLS_FILE = 'docker-compose-dev-tools.yaml';

    public const DOCKERIZER_DIR = '.dockerizer';

    /**
     * @var string[]
     */
    private array $regularParameterNames;

    /**
     * @var Service[]
     */
    private array $services = [];

    /**
     * @var Service[]
     */
    private array $servicesByName = [];

    private Template $template;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param ModifierCollection $modifierCollection
     * @param \DefaultValue\Dockerizer\DependencyInjection\Factory $factory
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private ModifierCollection $modifierCollection,
        private \DefaultValue\Dockerizer\DependencyInjection\Factory $factory
    ) {
    }

    /**
     * @return Template
     */
    public function getTemplate(): Template
    {
        return $this->template;
    }

    /**
     * @param Template $template
     * @return $this
     */
    public function setTemplate(Template $template): self
    {
        if (isset($this->template)) {
            throw new \DomainException('Composition template is already set!');
        }

        $this->template = $template;

        return $this;
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    public function addService(string $serviceName): self
    {
        /** @var Service $service */
        $service = $this->template->getPreconfiguredServiceByName($serviceName);
        // @TODO: validate environment variables used by the service
        // @TODO: validate service variables. All services must have the same value for the same variables, because
        // input option overwrite ALL preconfigured values
        // $service->validate();
        $this->services[] = $service;
        $this->servicesByName[$service->getName()] = $service;

        return $this;
    }

    /**
     * @param string $name
     * @return Service
     */
    public function getService(string $name): Service
    {
        return $this->servicesByName[$name];
    }

    /**
     * A list of options to be fetched via regular option. All other options are fetched from input via
     * UniversalReusableOption.
     * Any extra options that are not found in the service files will be skipped.
     *
     * @param array $regularParameterNames
     * @return void
     */
    public function setRegularParameterNames(array $regularParameterNames): void
    {
        $this->regularParameterNames = $regularParameterNames;
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        $parameters = [
            'by_service' => [],
            'regular_options' => [],
            'universal_options' => []
        ];

        foreach ($this->servicesByName as $service) {
            $serviceParameters = $service->getParameters();
            $parameters['by_service'][$service->getName()] = $serviceParameters;
            $parameters['regular_options'][] = array_intersect(
                array_keys($serviceParameters),
                $this->regularParameterNames
            );
            $parameters['universal_options'][] = array_diff(
                array_keys($serviceParameters),
                $this->regularParameterNames
            );
        }

        $parameters['regular_options'] = array_unique(array_merge(...$parameters['regular_options']));
        $parameters['universal_options'] = array_unique(array_merge(...$parameters['universal_options']));

        return $parameters;
    }

    /**
     * Get parameter value - from the service (input or preconfigured) or global if other values are not available
     *
     * @param string $parameter
     * @return null|string|int|float
     */
    public function getParameterValue(string $parameter): null|string|int|float
    {
        foreach ($this->servicesByName as $service) {
            try {
                return $service->getParameterValue($parameter);
            } catch (\Exception) {
            }
        }

        return $this->getTemplate()->getPreconfiguredParameterValue($parameter);
    }

    /**
     * @param string $parameter
     * @param mixed $value
     * @return void
     */
    public function setServiceParameter(string $parameter, mixed $value): void
    {
        foreach ($this->servicesByName as $service) {
            $service->setParameterValue($parameter, $value);
        }
    }

    /**
     * @param string $parameter
     * @return bool
     */
    public function isParameterMissed(string $parameter): bool
    {
        foreach ($this->servicesByName as $service) {
            try {
                $parameters = $service->getParameters();

                if (isset($parameters[$parameter])) {
                    $service->getParameterValue($parameter);
                }
            } catch (\Exception) {
                return true;
            }
        }

        return false;
    }

    /**
     * @TODO: Maybe should move this to some external service. Will leave here for now because YAGNI
     *
     * @param OutputInterface $output
     * @param string $projectRoot
     * @param bool $force
     * @return ModificationContext
     */
    public function dump(OutputInterface $output, string $projectRoot, bool $force): ModificationContext
    {
        // 0. Sort services, get container name from the first service that has is
        $this->sortServices();

        // 1. Dump main `docker-compose.yaml` file
        $modificationContext = $this->compileDockerCompose($output, $projectRoot, $force);
        $dockerComposeDir = $modificationContext->getDockerComposeDir();
        $this->filesystem->filePutContents(
            $dockerComposeDir . self::DOCKER_COMPOSE_FILE,
            Yaml::dump($modificationContext->getCompositionYaml(), 32, 2)
        );

        if ($readme = $modificationContext->getReadme()) {
            $this->filesystem->filePutContents($dockerComposeDir . 'Readme.md', implode("\n\n\n", $readme));
        }

        // 2. Dump dev tools if available
        if ($modificationContext->getDevToolsYaml()) {
            $this->filesystem->filePutContents(
                $dockerComposeDir . self::DOCKER_COMPOSE_DEV_TOOLS_FILE,
                Yaml::dump($modificationContext->getDevToolsYaml(), 32, 2)
            );
        }

        $output->writeln('');
        $output->writeln('Final service parameters list:');
        $parameters = $this->getParameters();

        foreach (array_merge($parameters['regular_options'], $parameters['universal_options']) as $parameter) {
            $message = sprintf(
                '- <info>%s</info>: <info>%s</info>',
                $parameter,
                $this->getParameterValue($parameter)
            );
            $output->writeln($message);
        }

        // 3. Dump all mounted files
        $mountedFiles = [];

        foreach ($this->services as $service) {
            // Yes, the same service can be added several times with different files
            $mountedFiles[] = $service->compileMountedFiles();
        }

        if ($mountedFiles = array_unique(array_merge(...$mountedFiles))) {
            $output->writeln('');
            $output->writeln('Mounted files list:');
        }

        foreach ($mountedFiles as $relativeFileName => $mountedFileContent) {
            $this->filesystem->filePutContents($dockerComposeDir . $relativeFileName, $mountedFileContent);
            $output->writeln('- ' . $relativeFileName);
        }

        $output->writeln('');

        return $modificationContext;
    }

    /**
     * @param string $projectRoot
     * @return string
     */
    public function getDockerizerDirInProject(string $projectRoot): string
    {
        return $projectRoot . self::DOCKERIZER_DIR . DIRECTORY_SEPARATOR;
    }

    /**
     * Sort services to match their order in template:
     * - get docker-compose version from the first service file
     * - get first available container name to dump composition to or generate a random one if missed
     * - keep correct order of overwriting and merging service configurations
     *
     * @return void
     */
    private function sortServices(): void
    {
        $selectedServices = $this->services;
        $this->services = [];
        $templateServices = array_merge(
            array_values($this->template->getServices(Service::TYPE_REQUIRED)),
            array_values($this->template->getServices(Service::TYPE_OPTIONAL))
        );
        $templateServices = array_merge(...$templateServices);
        $templateServicesOrder = array_keys($templateServices);

        foreach ($selectedServices as $service) {
            $index = array_search($service->getName(), $templateServicesOrder, true);

            if (!is_int($index)) {
                throw new \RuntimeException(
                    'CRITICAL: Composition contains services that are not present in the template!'
                );
            }

            $this->services[$index] = $service;
        }

        ksort($this->services);
    }

    /**
     * @param OutputInterface $output
     * @param string $projectRoot
     * @param bool $force
     * @return ModificationContext
     */
    private function compileDockerCompose(
        OutputInterface $output,
        string $projectRoot,
        bool $force
    ): ModificationContext {
        $compositionYaml = [];
        $devToolsYaml = [];
        $firstContainerWithName = null;

        foreach ($this->services as $service) {
            $compiledYaml = Yaml::parse($service->compileServiceFile());
            $compositionYaml[] = $compiledYaml;
            $devToolsYaml[] = $service->compileDevTools();
            $services = array_filter(array_map(static function ($serviceYaml) {
                return $serviceYaml['container_name'] ?? null;
            }, $compiledYaml['services']));

            if (!$firstContainerWithName && count($services)) {
                $firstContainerWithName = array_shift($services);
            }
        }

        $firstContainerWithName ??= uniqid('composition_', false);
        $dockerComposeDir = $this->getDockerizerDirInProject($projectRoot)
            . $firstContainerWithName . DIRECTORY_SEPARATOR;
        $this->prepareDirectoryToDumpComposition($output, $dockerComposeDir, $force);

        $dockerComposeVersion = $compositionYaml[0]['version'];
        $compositionYaml = array_merge_recursive(...$compositionYaml);
        $compositionYaml['version'] = $dockerComposeVersion;

        // Parse, compile and combine all dev tools into one array
        $devToolsYaml = array_merge_recursive(...array_map(
            static fn (string $yaml) => Yaml::parse($yaml),
            array_merge(...array_filter($devToolsYaml))
        ));
        $devToolsYaml['version'] = $dockerComposeVersion;

        $modificationContext = $this->prepareContext(
            $compositionYaml,
            $devToolsYaml,
            $projectRoot,
            $dockerComposeDir
        );
        $this->modifierCollection->modify($modificationContext);

        return $modificationContext;
    }

    /**
     * @param OutputInterface $output
     * @param string $dockerComposeDir
     * @param bool $force
     * @return void
     */
    private function prepareDirectoryToDumpComposition(
        OutputInterface $output,
        string $dockerComposeDir,
        bool $force
    ): void {
        // If the path already exists - try stopping any composition(s) defined there
        if ($this->filesystem->exists($dockerComposeDir)) {
            if ($force) {
                if (is_dir($dockerComposeDir) && !$this->filesystem->isEmptyDir($dockerComposeDir)) {
                    $output->writeln("<comment>Shutting down compositions (if any) in: $dockerComposeDir</comment>");
                    $this->dockerCompose->initialize($dockerComposeDir)->down();
                }

                $this->filesystem->remove($dockerComposeDir);
            } else {
                throw new \RuntimeException(
                    "Directory $dockerComposeDir already exists and not empty. Add `-f` to force override its content."
                );
            }
        }

        $this->filesystem->mkdir($dockerComposeDir);
    }

    /**
     * @param array $yamlContent
     * @param array $devToolsYaml
     * @param string $projectRoot
     * @param string $dockerComposeDir
     * @return ModificationContext
     */
    private function prepareContext(
        array $yamlContent,
        array $devToolsYaml,
        string $projectRoot,
        string $dockerComposeDir
    ): ModificationContext {
        /** @var ModificationContext $modificationContext */
        $modificationContext = $this->factory->get(ModificationContext::class);

        return $modificationContext->setDockerComposeDir($dockerComposeDir)
            ->setProjectRoot($projectRoot)
            ->setCompositionYaml($yamlContent)
            ->setDevToolsYaml($devToolsYaml);
    }
}
