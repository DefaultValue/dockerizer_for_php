<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Finder\SplFileInfo;

class Service implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    private SplFileInfo $fileInfo;

    private string $code;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
    ) {
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @return $this
     */
    public function setFileInfo(SplFileInfo $fileInfo): self
    {
        if (isset($this->fileInfo)) {
            // This is not a value object, which is better for testing during active development
            throw new \RuntimeException('Attempt to change the stateful service');
        }

        $this->fileInfo = $fileInfo;
        $this->validate();

        return $this;
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

    private function validate(array $parameters = [])
    {
        // @TODO: validate volumes and mounted files in the service. Must ensure that volumes exist and mounted files are present in the FS
        $this->fileInfo->getRealPath();
    }

    private function getParameters()
    {
        throw new \RuntimeException('Get service parameters: not implemented');
        // $this->getServiceFiles
    }

    private function collectServiceFiles(): self
    {
        // $this->fileCollection->addFile($this->fileInfo->getRealPath());
        return $this;
    }

    public function dumpServiceFile(array $parameters, bool $write = true): string
    {
        $this->validate($parameters);

        return $this->serviceParameter->apply($this->fileInfo->getContents(), $parameters);
    }

    public function dumpMountedFiles(array $parameters, bool $write = true)
    {
        $this->validate($parameters);

        // @TODO: initialize ALL files in `collectServiceFiles`
        $content = $this->serviceParameter->apply($this->fileInfo->getContents(), $parameters);

        $files[$this->fileInfo->getRealPath()] = $content;

        return $files;
    }
}
