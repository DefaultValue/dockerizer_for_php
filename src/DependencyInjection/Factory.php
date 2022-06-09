<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\DependencyInjection;

/**
 * Factory for non-shared public services.
 * This way `new` operator is not used to create services, and DI container can initialize their dependencies
 */
class Factory
{
    /**
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     */
    public function __construct(private \Symfony\Component\DependencyInjection\ContainerInterface $container)
    {
    }

    /**
     * @param string $dto
     * @return object
     */
    public function get(string $dto): object
    {
        $newInstance = $this->container->get($dto);

        if (!($newInstance instanceof DataTransferObjectInterface)) {
            throw new \DomainException(
                "Object of class $dto does not instantiate DataTransferObjectInterface! Use Singletons for it instead!"
            );
        }

        return $newInstance;
    }
}
