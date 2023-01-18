<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;

use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql\Metadata\MetadataKeys as MysqlMetadataKeys;

class Metadata
{
    private string $vendorImage;

    /**
     * @var string[]
     */
    private array $environment;

    private string $myCnfMountDestination;

    private string $myCnf;

    private string $targetImage;

    /**
     * @param array{
     *     'vendor_image': string,
     *     'environment': string[],
     *     'my_cnf_mount_destination': string,
     *     'my_cnf': string,
     *     'target_image': string
     * } $metadata
     * @return Metadata
     */
    public function fromArray(array $metadata): self
    {
        $this->validateMetadata($metadata);

        $self = new self();
        $self->setVendorImage($metadata[MysqlMetadataKeys::VENDOR_IMAGE])
            ->setEnvironment($metadata[MysqlMetadataKeys::ENVIRONMENT])
            ->setMyCnfMountDestination($metadata[MysqlMetadataKeys::MY_CNF_MOUNT_DESTINATION])
            ->setMyCnf($metadata[MysqlMetadataKeys::MY_CNF])
            ->setTargetImage($metadata[MysqlMetadataKeys::TARGET_IMAGE]);

        return $self;
    }

    /**
     * @return array{
     *     'vendor_image': string,
     *     'environment': string[],
     *     'my_cnf_mount_destination': string,
     *     'my_cnf': string,
     *     'target_image': string
     * }
     */
    public function toArray(): array
    {
        return [
            MysqlMetadataKeys::VENDOR_IMAGE => $this->getVendorImage(),
            MysqlMetadataKeys::ENVIRONMENT => $this->getEnvironment(),
            MysqlMetadataKeys::MY_CNF_MOUNT_DESTINATION => $this->getMyCnfMountDestination(),
            MysqlMetadataKeys::MY_CNF => $this->getMyCnf(),
            MysqlMetadataKeys::TARGET_IMAGE => $this->getTargetImage()
        ];
    }

    /**
     * @param string $json
     * @return Metadata
     * @throws \JsonException
     */
    public function fromJson(string $json): self
    {
        return $this->fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return string
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @return string
     */
    public function getVendorImage(): string
    {
        return $this->vendorImage;
    }

    /**
     * @return string[]
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /**
     * @return string
     */
    public function getMyCnfMountDestination(): string
    {
        return $this->myCnfMountDestination;
    }

    /**
     * @return string
     */
    public function getMyCnf(): string
    {
        return $this->myCnf;
    }

    /**
     * @return string
     */
    public function getTargetImage(): string
    {
        return $this->targetImage;
    }

    /**
     * @param array<string, string|string[]> $metadata
     * @return void
     */
    private function validateMetadata(array $metadata): void
    {
        foreach (MysqlMetadataKeys::cases() as $case) {
            if (!isset($metadata[$case])) {
                throw new \RuntimeException(sprintf('Metadata key "%s" is missing', $case));
            }

            if (
                is_string($metadata[$case])
                && (!$metadata[$case] && $case !== MysqlMetadataKeys::MY_CNF)
            ) {
                throw new \RuntimeException(sprintf('Metadata key "%s" is empty', $case));
            }
        }
    }

    /**
     * @param string $vendorImage
     * @return Metadata
     */
    private function setVendorImage(string $vendorImage): Metadata
    {
        $this->vendorImage = $vendorImage;

        return $this;
    }

    /**
     * @param string[] $environment
     * @return Metadata
     */
    private function setEnvironment(array $environment): Metadata
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * @param string $myCnfMountDestination
     * @return Metadata
     */
    private function setMyCnfMountDestination(string $myCnfMountDestination): Metadata
    {
        $this->myCnfMountDestination = $myCnfMountDestination;

        return $this;
    }

    /**
     * @param string $myCnf
     * @return Metadata
     */
    private function setMyCnf(string $myCnf): Metadata
    {
        $this->myCnf = $myCnf;

        return $this;
    }

    /**
     * @param string $targetImage
     */
    private function setTargetImage(string $targetImage): void
    {
        $this->targetImage = $targetImage;
    }
}
