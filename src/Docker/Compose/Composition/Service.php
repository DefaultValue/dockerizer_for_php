<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

class Service extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile
    implements \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\Service\Parameter $serviceParameter
    ) {
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

    protected function validate(array $parameters = []): void
    {
        // @TODO: validate volumes and mounted files in the service. Must ensure that volumes exist and mounted files are present in the FS
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

        return $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);
    }

    public function dumpMountedFiles(array $parameters, bool $write = true)
    {
        $this->validate($parameters);

        // @TODO: initialize ALL files in `collectServiceFiles`
        $content = $this->serviceParameter->apply($this->getFileInfo()->getContents(), $parameters);

        $files[$this->getFileInfo()->getRealPath()] = $content;

        return $files;
    }
}
