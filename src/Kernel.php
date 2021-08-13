<?php

namespace App;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class Kernel
{
    private static ContainerBuilder $containerBuilder;

    /**
     * @param array $configDirectories
     * @return Application
     * @throws \Exception
     */
    public function getApplication(array $configDirectories): Application
    {
        $containerBuilder = $this->boot($configDirectories);
        $commandLoader = $containerBuilder->get('console.command_loader');

        $application = new Application();
        $application->setCommandLoader($commandLoader);

        return $application;
    }

    /**
     * @param array $configDirectories
     * @return ContainerBuilder
     * @throws \Exception
     */
    private function boot(array $configDirectories): ContainerBuilder
    {
        $fileLocator = new FileLocator($configDirectories);

        $containerBuilder = $this->getContainerBuilder();
        $yamlFileLoader = new YamlFileLoader($containerBuilder, $fileLocator);
        $yamlFileLoader->load('services.yaml');

        $containerBuilder->addCompilerPass(new AddConsoleCommandPass());
        $containerBuilder->compile();

        return $containerBuilder;
    }

    /**
     * @return ContainerBuilder
     */
    private function getContainerBuilder(): ContainerBuilder
    {
        self::$containerBuilder ??= new ContainerBuilder();

        return self::$containerBuilder;
    }
}
