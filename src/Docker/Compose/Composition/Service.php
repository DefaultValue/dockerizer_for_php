<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\SplFileInfo;

/**
 * A single Docker Composition part: runner, required or optional service.
 * Ideally, every service file must contain one docker-compose service definition.
 * It can contain multiple definitions in case they are tightly connected with each other.
 * Be careful with naming if you put multiple services in a single file!
 * Ensure other files do not contain services with identical names!
 */
class Service extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile
    implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    public const TYPE = 'type'; // Either runner, required or optional
    private const CONFIG_KEY_LINK_TO = 'link_to';
    private const CONFIG_KEY_DEV_TOOLS = 'dev_tools';
    private const CONFIG_KEY_PARAMETERS = 'parameters';
    // parameters

    public const TYPE_RUNNER = 'runner';

    private array $knownConfigKeys = [
        self::CONFIG_KEY_DEV_TOOLS,
        self::CONFIG_KEY_LINK_TO,
        self::CONFIG_KEY_PARAMETERS,
        self::TYPE,
    ];

    private string $type;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
    ) {
    }

    public function preconfigure(array $config)
    {
        if ($unknownConfigKeys = array_diff(array_keys($config), $this->knownConfigKeys)) {
            throw new \InvalidArgumentException(sprintf(
                'Service pre-configuration for \'%s\' contains unknown parameters: %s',
                $this->getCode(),
                implode($unknownConfigKeys)
            ));
        }

        $this->type = $config[self::TYPE];

        $foo = false;
//        parameters - must not contain unknown parameters
//        get all parameters, compare arrays
    }

    protected function validate(array $parameters = []): void
    {
        // @TODO: validate volumes and mounted files in the service. Must ensure that volumes exist and mounted files are present in the FS
    }

    public function getType(): string
    {
        return (string) $this->type;
    }

    public function setConfig(array $config): self
    {

    }

    /**
     * Get service parameters, but skip existing ones if passed
     * Can be used to get parameters metadata or to get missed input parameters to request from the user
     *
     * @param array $existingParameters
     * @return array
     */
    public function getMissedParameters(array $existingParameters): array
    {
        $parameters = [];

        return $parameters;
    }

    private function collectServiceFiles(): self
    {
        // $this->fileCollection->addFile($this->fileInfo->getRealPath());
        return $this;
    }

    public function dumpServiceFile(array $parameters, bool $write = true): string
    {
        $this->validate();

        return $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);
    }

    public function dumpMountedFiles(array $parameters, bool $write = true)
    {
        $this->validate();

        // @TODO: initialize ALL files in `collectServiceFiles`
        $content = $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);

        $files[$this->getFileInfo()->getRealPath()] = $content;

        return $files;
    }
}
