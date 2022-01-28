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
    public const CONFIG_KEY_LINK_TO = 'link_to';
    public const CONFIG_KEY_DEV_TOOLS = 'dev_tools';
    public const CONFIG_KEY_PARAMETERS = 'parameters';
    // parameters

    public const TYPE_RUNNER = 'runner';
    public const TYPE_REQUIRED = 'required';
    public const TYPE_OPTIONAL = 'optional';
    // public const TYPE_RUNNER = 'runner';
    public const TYPE_DEV_TOOLS = 'dev_tools';

    private array $knownConfigKeys = [
        self::CONFIG_KEY_DEV_TOOLS,
        self::CONFIG_KEY_LINK_TO,
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

    public function getParameters()
    {
        // return parameter collection to represent named and/or preconfigured parameters?
    }

    /**
     * Get service parameters, but skip existing ones if passed
     * Can be used to get parameters metadata or to get missed input parameters to request from the user
     *
     * @return array
     */
    public function getMissedParameters(): array
    {
        $missedParameters = [];

        foreach ($this->getOriginalFiles() as $realpath) {
            preg_match_all('/\{\{(.*)\}\}/U', file_get_contents($realpath), $matches);
            $missedParameters[$realpath] = count($matches) ? $matches[1] : [];
        }

        return $missedParameters;
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

//    public function dumpServiceFile(array $parameters, bool $write = true): string
//    {
//        $this->validate();
//
//        return $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);
//    }
//
//    public function dumpMountedFiles(array $parameters, bool $write = true)
//    {
//        $this->validate();
//
//        // @TODO: initialize ALL files in `collectServiceFiles`
//        $content = $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);
//
//        $files[$this->getFileInfo()->getRealPath()] = $content;
//
//        return $files;
//    }

    public function getPreconfiguredMainFile()
    {
        $content = file_get_contents($this->getFileInfo()->getRealPath());
        $parameters = [
            'domains' => 'google.com www.google.com',
            'php_version' => '5.6',
            'environment' => 'production',
            'composer_version' => '1'
        ];
        $content = $this->serviceParameter->apply($content, $parameters);

//        foreach ($this->parameters as $parameter) {
//            $content = $this->serviceParameter->apply($content, [
//
//            ]);
//        }

        return $content;
    }
}
