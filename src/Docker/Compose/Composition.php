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

    /**
     * @var Service[]
     */
    private array $additionalServices = [];

    /**
     * @var Service[]
     */
    private array $servicesByName = [];

    private Template $template;

    private Service $runner;

    private Service $devTools;

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
        //  @TODO: validate environment variables used by the service
        // $service->validate();

        if ($service->getType() === Service::TYPE_RUNNER) {
            if (isset($this->runner)) {
                throw new \RuntimeException(sprintf(
                    'Composition runner is already set. Old runner: %s. New runner: %s',
                    $this->runner->getName(),
                    $service->getName()
                ));
            }

            $this->runner = $service;
            $this->servicesByName[$service->getName()] = $service;
        } else {
            $this->additionalServices[$service->getName()] = $service;
            $this->servicesByName[$service->getName()] = $service;
        }

        // Dev tools is not an additional service. This yaml file is stored separately from the main file
        // Thus we do not add `$devTools` to `$this->additionalServices`
        $devToolsNameByConvention = $service->getName() . '_' . Service::CONFIG_KEY_DEV_TOOLS;

        if ($devTools = $this->getTemplate()->getPreconfiguredServiceByName($devToolsNameByConvention)) {
            if (isset($this->devTools)) {
                throw new \InvalidArgumentException('Multiple dev tools are not yet supported');
            }

            $this->servicesByName[$devTools->getName()] = $devTools;
            $this->devTools = $devTools;
        }

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
     * @return array
     */
    public function getParameters(): array
    {
        $parameters = [
            'by_service' => [],
            'all' => [],
            'missed' => []
        ];

        foreach ($this->servicesByName as $service) {
            $serviceParameters = $service->getParameters();
            $parameters['by_service'][$service->getName()] = $service->getParameters();
            $parameters['all'][] = $serviceParameters['all'];
            $parameters['missed'][] = $serviceParameters['missed'];
        }

        $parameters['all'] = array_unique(array_merge(...$parameters['all']));
        $parameters['missed'] = array_unique(array_merge(...$parameters['missed']));

        return $parameters;
    }

    /**
     * @param string $parameter
     * @param mixed $value
     * @return void
     */
    public function setServiceParameter(string $parameter, mixed $value): void
    {
        foreach ($this->servicesByName as $service) {
            $service->setParameterIfMissed($parameter, $value);
        }
    }

    /**
     * @TODO: Maybe should move this to some external service. Will leave here for now because YAGNI
     *
     * @param OutputInterface $output
     * @param string $projectRoot
     * @param bool $force
     * @return void
     */
    public function dump(OutputInterface $output, string $projectRoot, bool $force): void
    {
        $runnerYaml = Yaml::parse($this->runner->compileServiceFile());
        $mainService = array_keys($runnerYaml['services'])[0];
        $mainContainerName = $runnerYaml['services'][$mainService]['container_name'];
        $dockerComposeDir = $projectRoot . '.dockerizer' . DIRECTORY_SEPARATOR . $mainContainerName;
        $this->prepareDirectoryToDumpComposition($output, $dockerComposeDir, $force);
        $dockerComposeDir .= DIRECTORY_SEPARATOR;

        // 1. Dump main file
        $compositionYaml = [$runnerYaml];
        $mountedFiles = [$this->runner->compileMountedFiles()];

        foreach ($this->additionalServices as $service) {
            $compositionYaml[] = Yaml::parse($service->compileServiceFile());
            // Yes, the same service can be added several times with different files
            $mountedFiles[] = $service->compileMountedFiles();
        }

        $compositionYaml = array_replace_recursive(...$compositionYaml);
        $compositionYaml['version'] = $runnerYaml['version'];
        $devToolsYaml = [];

        if (isset($this->devTools)) {
            $devToolsYaml = Yaml::parse($this->devTools->compileServiceFile());
        }

        $modificationContext = $this->prepareContext(
            $compositionYaml,
            $devToolsYaml,
            $projectRoot,
            $dockerComposeDir
        );
        $this->modifierCollection->modify($modificationContext);

        $this->filesystem->dumpFile(
            $dockerComposeDir . self::DOCKER_COMPOSE_FILE,
            Yaml::dump($modificationContext->getCompositionYaml(), 32, 2)
        );

        if ($readme = $modificationContext->getReadme()) {
            $this->filesystem->dumpFile($dockerComposeDir . 'Readme.md', implode("\n\n", $readme));
        }

        // 2. Dump dev tools
        if (isset($this->devTools)) {
            $this->filesystem->dumpFile(
                $dockerComposeDir . self::DOCKER_COMPOSE_DEV_TOOLS_FILE,
                Yaml::dump($modificationContext->getDevToolsYaml(), 32, 2)
            );
            $mountedFiles[] = $this->devTools->compileMountedFiles();
        }

        // 3. Dump all mounted files
        $mountedFiles = array_unique(array_merge(...$mountedFiles));

        foreach ($mountedFiles as $relativeFileName => $mountedFileContent) {
            $this->filesystem->dumpFile($dockerComposeDir . $relativeFileName, $mountedFileContent);
        }
    }

    /**
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
                if (is_dir($dockerComposeDir)) {
                    $output->writeln("<comment>Shutting down compositions (if any) in: $dockerComposeDir</comment>");
                    $this->dockerCompose->setCwd($dockerComposeDir)->down();
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
