<?php

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

    private string $awsS3Bucket;

    private string $targetImage;

    /**
     * @param array{
     *     'vendor_image': string,
     *     'environment': string[],
     *     'my_cnf_mount_destination': string,
     *     'my_cnf': string,
     *     'aws_s3_bucket': string,
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
            ->setAwsS3Bucket($metadata[MysqlMetadataKeys::AWS_S3_BUCKET])
            ->setTargetImage($metadata[MysqlMetadataKeys::TARGET_IMAGE]);

        return $self;
    }

    /**
     * @return array{
     *     'vendor_image': string,
     *     'environment': string[],
     *     'my_cnf_mount_destination': string,
     *     'my_cnf': string,
     *     'aws_s3_bucket': string,
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
            MysqlMetadataKeys::AWS_S3_BUCKET => $this->getAwsS3Bucket(),
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
    public function getAwsS3Bucket(): string
    {
        return $this->awsS3Bucket;
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
     * @param string $awsS3Bucket
     * @return Metadata
     */
    private function setAwsS3Bucket(string $awsS3Bucket): Metadata
    {
        $this->awsS3Bucket = $awsS3Bucket;

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
