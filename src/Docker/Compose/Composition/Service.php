<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

/**
 * A single Docker Composition part: runner, required or optional service.
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
class Service extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile
    implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const TYPE = 'type'; // Either runner, required or optional
    public const CONFIG_KEY_DEV_TOOLS = 'dev_tools';
    public const CONFIG_KEY_PARAMETERS = 'parameters';
    // parameters

    public const TYPE_RUNNER = 'runner';
    public const TYPE_REQUIRED = 'required';
    public const TYPE_OPTIONAL = 'optional';
    public const TYPE_DEV_TOOLS = 'dev_tools';

    private array $knownConfigKeys = [
        self::CONFIG_KEY_DEV_TOOLS,
        self::CONFIG_KEY_PARAMETERS,
        self::TYPE,
    ];

    /**
     * Max file size to search for options. Larger files will be skipped as most likely they are not configuration files
     */
    private const MAX_MOUNTED_FILE_SIZE = 1024 * 1024;

    private array $config;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
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
                $this->getCode(),
                implode($unknownConfigKeys)
            ));
        }

        $config['name'] = $name;
        $this->config = $config;
    }

    protected function validate(array $parameters = []): void
    {
        // @TODO: validate volumes and mounted files in the service. Must ensure that volumes exist and mounted files are present in the FS
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
     * @return array[]
     */
    public function getParameters(): array
    {
        $parameters = [
            'by_file' => [],
            'all' => [],
            'missed' => []
        ];

        foreach ($this->getOriginalFiles() as $realpath) {
            $fileParameters = [];

            // @TODO: Filesystem\Firewall, create another service to read files
            foreach ($this->serviceParameter->extractParameters(file_get_contents($realpath)) as $match) {
                $fileParameters[] = $this->serviceParameter->getNameFromDefinition($match);
            }

            $fileParameters = array_unique($fileParameters);

            if (!$fileParameters) {
                continue;
            }

            $parameters['all'][] = $fileParameters;

            foreach ($fileParameters as $parameter) {
                $parameters['by_file'][$parameter][] = $realpath;

                if (!isset($this->config[self::CONFIG_KEY_PARAMETERS][$parameter])) {
                    $parameters['missed'][] = $parameter;
                }
            }
        }

        $parameters['all'] = array_unique(array_merge(...$parameters['all']));
        $parameters['missed'] = array_unique($parameters['missed']);

        return $parameters;
    }

    /**
     * Set parameter if missed. Do not allow changing preconfigured parameters that are defined in templates
     *
     * @param string $parameterName
     * @param mixed $value
     * @return void
     */
    public function setParameterIfMissed(string $parameterName, mixed $value): void
    {
        // @TODO: validate parameter that is set here
        if (!isset($this->config[self::CONFIG_KEY_PARAMETERS][$parameterName])) {
            $this->config[self::CONFIG_KEY_PARAMETERS][$parameterName] = $value;
        }
    }

    /**
     * @return string
     */
    public function compileServiceFile(): string
    {
        $this->validate();
        // @TODO: Filesystem\Firewall, create another service to read files
        $content = file_get_contents($this->getFileInfo()->getRealPath());

        return $this->serviceParameter->apply($content, $this->config[self::CONFIG_KEY_PARAMETERS]);
    }

    /**
     * Array of file path and file content:
     * [
     *     'file_1' => 'compiled content',
     *     'file_2' => 'compiled content'
     * ]
     *
     * @return array
     */
    public function compileMountedFiles(): array
    {
        $this->validate();
        $mountedFiles = $this->getOriginalFiles();
        array_shift($mountedFiles);
        $compiledFiles = [];

        foreach ($mountedFiles as $mountedFileName) {
            // @TODO: Filesystem\Firewall, create another service to read files
            $compiledFiles[$mountedFileName] = $this->serviceParameter->apply(
                file_get_contents($mountedFileName),
                $this->config[self::CONFIG_KEY_PARAMETERS]
            );
        }

        return $compiledFiles;
    }

    /**
     * Get all template files: main file with service definition and all mounted files (incl. files inside directories)
     *
     * @return array
     */
    private function getOriginalFiles(): array
    {
        $mainFile = $this->getFileInfo()->getRealPath();
        $files = [$mainFile];
        $mainFileDirectory = dirname($mainFile) . DIRECTORY_SEPARATOR;
        $serviceAsArray = Yaml::parseFile($mainFile);

        foreach ($serviceAsArray['services'] as $serviceConfig) {
            if (!isset($serviceConfig['volumes'])) {
                continue;
            }

            foreach ($serviceConfig['volumes'] as $volume) {
                $relativePath = trim(explode(':', $volume)[0], '/');
                $fullMountPath = $mainFileDirectory . $relativePath;
                // Native \SplFileInfo is used here!
                $mountInfo = new \SplFileInfo($fullMountPath);

                // @TODO: must use realpath to check files are inside the DFP project
                if ($mountInfo->isLink()) {
                    continue;
                }

                if ($mountInfo->isFile() && $mountInfo->getSize() < self::MAX_MOUNTED_FILE_SIZE) {
                    $files[] = $fullMountPath;
                }

                if ($mountInfo->isDir() && !(new \DirectoryIterator($fullMountPath))->isDot()) {
                    $fullMountPath .= DIRECTORY_SEPARATOR;
                    $this->locateMountedFilesInDir($files, $fullMountPath);
                }
            }
        }

        return array_unique($files);
    }

    /**
     * @param array $files
     * @param string $fullMountPath - with DIRECTORY_SEPARATOR at the end
     * @return void
     */
    private function locateMountedFilesInDir(array &$files, string $fullMountPath): void
    {
        $directoryIterator = new \RecursiveDirectoryIterator($fullMountPath, \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directoryIterator) as $fileInfo) {
            if ($fileInfo->isLink() || $fileInfo->getSize() > self::MAX_MOUNTED_FILE_SIZE) {
                continue;
            }

            $realpath = $fileInfo->getRealPath();

            if (!str_starts_with($realpath, $fullMountPath)) {
                throw new \InvalidArgumentException("Service path: $fullMountPath, expected mounted path: $realpath");
            }

            $files[] = $fileInfo->getRealPath();
        }
    }
}
