<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * A single Docker Composition part: required or optional service.
 * Ideally, every service file must contain one docker-compose service definition.
 * It can contain multiple definitions in case they are tightly connected with each other.
 * Be careful with naming if you put multiple services in a single file.
 * Ensure other files do not contain services with identical names!
 *
 * CAUTION! Service instances are cloned when preconfiguring them with parameters from template.
 * For now, `__clone()` is not implemented because Service does not contain dependencies
 *
 * Service code = file name without extension
 * Service name = name given to a service in the composition template. The same service template can be re-used with
 * different parameters under different names
 */
class Service extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile implements
    \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const TYPE = 'type'; // Either required or optional, passed from the template
    public const TYPE_REQUIRED = 'required';
    public const TYPE_OPTIONAL = 'optional';
    public const TYPE_DEV_TOOLS = 'dev_tools';

    public const CONFIG_KEY_DEV_TOOLS = 'dev_tools';
    public const CONFIG_KEY_PARAMETERS = 'parameters';

    private array $knownConfigKeys = [
        self::CONFIG_KEY_DEV_TOOLS,
        self::CONFIG_KEY_PARAMETERS,
        self::TYPE,
    ];

    private array $config;

    /**
     * @var DevTools[]
     */
    private array $devTools = [];

    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter,
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\DevTools\Collection $devToolsCollection,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
    ) {
    }

    /**
     * @param string $name
     * @param array $config
     * @return void
     */
    public function preconfigure(string $name, array $config): void
    {
        if ($unknownConfigKeys = array_diff(array_keys($config), $this->knownConfigKeys)) {
            throw new \InvalidArgumentException(sprintf(
                'Service pre-configuration for \'%s\' contains unknown parameters: %s',
                $this->getName(),
                implode($unknownConfigKeys)
            ));
        }

        $config['name'] = $name;
        $this->config = $config;
        $this->addDevTools();
    }

    protected function validate(array $parameters = []): void
    {
        // @TODO: validate volumes and mounted files in the service.
        // Must ensure that volumes exist and mounted files are present in the FS
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->config['name'];
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return (string) $this->config[self::TYPE];
    }

    /**
     * Get information about parameter names and files where they appear
     *
     * @return array[]
     */
    public function getParameters(): array
    {
        $parameters = [];

        foreach ($this->getOriginalFiles() as $realpath) {
            $fileParameters = [];
            $content = $this->filesystem->fileGetContents($realpath);

            foreach ($this->serviceParameter->extractParameters($content) as $match) {
                $fileParameters[] = $this->serviceParameter->getNameFromDefinition($match);
            }

            $fileParameters = array_unique($fileParameters);

            if (!$fileParameters) {
                continue;
            }

            foreach ($fileParameters as $parameter) {
                $parameters[$parameter][] = $realpath;
            }
        }

        return $parameters;
    }

    /**
     * @param string $parameter
     * @return mixed
     */
    public function getParameterValue(string $parameter): mixed
    {
        return $this->config[self::CONFIG_KEY_PARAMETERS][$parameter]
            ?? throw new \InvalidArgumentException("Service parameter $parameter is not set");
    }

    /**
     * Set or update parameter. Parameters passed by the user have priority over the template parameters
     *
     * @param string $parameter
     * @param mixed $value
     * @return void
     */
    public function setParameterValue(string $parameter, mixed $value): void
    {
        if ($value === null) {
            // This should not happen, but need to test
            throw new \InvalidArgumentException("Value for $parameter must not be empty");
        }

        if (isset($this->getParameters()[$parameter])) {
            $this->config[self::CONFIG_KEY_PARAMETERS][$parameter] = $value;
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function compileServiceFile(): string
    {
        $this->validate();
        $content = $this->filesystem->fileGetContents($this->getFileInfo()->getRealPath());

        return $this->serviceParameter->apply($content, $this->config[self::CONFIG_KEY_PARAMETERS]);
    }

    /**
     * @return string[]
     */
    public function compileDevTools(): array
    {
        return array_map(function (DevTools $devTools) {
            $content = $this->filesystem->fileGetContents($devTools->getFileInfo()->getRealPath());

            return $this->serviceParameter->apply($content, $this->config[self::CONFIG_KEY_PARAMETERS]);
        }, $this->devTools);
    }

    /**
     * Array of file path and file content:
     * [
     *     'file_1' => 'compiled content',
     *     'file_2' => 'compiled content'
     * ]
     */
    public function compileMountedFiles(): array
    {
        $this->validate();
        $compiledFiles = [];

        foreach ($this->getMountedFiles() as $relativePath => $mountedFileName) {
            $compiledFiles[$relativePath] = $this->serviceParameter->apply(
                // @TODO: Do not save file content and reduce memory usage?
                $this->filesystem->fileGetContents($mountedFileName),
                $this->config[self::CONFIG_KEY_PARAMETERS]
            );
        }

        return $compiledFiles;
    }

    /**
     * Dev tools also may have options to enter, so need to deal with this like an individual service
     */
    private function addDevTools(): void
    {
        if (!isset($this->config[self::CONFIG_KEY_DEV_TOOLS])) {
            return;
        }

        $devToolCodes = is_array($this->config[self::CONFIG_KEY_DEV_TOOLS])
            ? $this->config[self::CONFIG_KEY_DEV_TOOLS]
            : [$this->config[self::CONFIG_KEY_DEV_TOOLS]];

        foreach ($devToolCodes as $devToolCode) {
            // Unlike the Service, DevTools do not have state, so `clone` is not used here
            $this->devTools[] = $this->devToolsCollection->getByCode($devToolCode);
        }
    }

    /**
     * Get all service files:
     * - main file with service definition
     * - dev tools
     * - all mounted files (incl. files inside directories)
     */
    private function getOriginalFiles(): array
    {
        return array_merge(
            $this->getOriginalDockerComposeFiles(),
            $this->getMountedFiles()
        );
    }

    /**
     * Get files used to build docker-compose*.yaml:
     * - main file with service definition
     * - dev tools
     *
     * @return array
     */
    private function getOriginalDockerComposeFiles(): array
    {
        return array_merge(
            [$this->getFileInfo()->getRealPath()],
            $this->getDevToolsFiles()
        );
    }

    /**
     * @return string[]
     */
    private function getDevToolsFiles(): array
    {
        return array_map(static function (DevTools $devTools) {
            return $devTools->getFileInfo()->getRealPath();
        }, $this->devTools);
    }

    /**
     * @return string[]
     */
    private function getMountedFiles(): array
    {
        $files = [];

        foreach ($this->getVolumes() as $volume => $dockerComposeFile) {
            $mainFileDirectory = dirname($dockerComposeFile) . DIRECTORY_SEPARATOR;
            $relativePath = trim(explode(':', $volume)[0], '/');
            $relativePath = ltrim($relativePath, './');

            // Skip mounting current directory
            if (!$relativePath) {
                continue;
            }

            $fullMountPath = $mainFileDirectory . $relativePath;
            $fileInfo = new \SplFileInfo($fullMountPath);

            if ($fileInfo->isLink()) {
                continue;
            }

            if ($fileInfo->isFile()) {
                $relativePath = str_replace($mainFileDirectory, '', $fileInfo->getRealPath());
                $files[$relativePath] = $fullMountPath;

                continue;
            }

            try {
                $foundFiles = Finder::create()->ignoreDotFiles(false)->in($fullMountPath)->files();

                foreach ($foundFiles as $fileInfo) {
                    $realpath = $fileInfo->getRealPath();

                    if (!str_starts_with($realpath, $fullMountPath)) {
                        throw new \InvalidArgumentException(
                            "Service path: $fullMountPath, expected mounted path: $realpath"
                        );
                    }

                    $relativePath = str_replace($mainFileDirectory, '', $realpath);
                    $files[$relativePath] = $realpath;
                }
            } catch (DirectoryNotFoundException) {
                // Ignore this case - maybe env variable is used or directory is configured in some other way,
                // added later, etc.
            }
        }

        return $files;
    }

    /**
     * Dev Tools can override original files because latest string keys override previous ones in array_merge
     */
    private function getVolumes(): array
    {
        $volumes = [];

        foreach ($this->getOriginalDockerComposeFiles() as $dockerComposeFile) {
            $volumes[] = array_fill_keys(
                array_column(Yaml::parseFile($dockerComposeFile)['services'], 'volumes')[0] ?? [],
                $dockerComposeFile
            );
        }

        return array_merge(...array_filter($volumes));
    }
}
