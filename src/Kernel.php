<?php

namespace DefaultValue\Dockerizer;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Dotenv\Dotenv;

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
        $this->initEnv();
        $containerBuilder = $this->boot($configDirectories);
        $commandLoader = $containerBuilder->get('console.command_loader');

        $application = new Application();
        $application->setCommandLoader($commandLoader);
        // @TODO: may be needed to get exception info for parallel runs
        // $application->setCatchExceptions(false);

        return $application;
    }

    /**
     * @return void
     */
    private function initEnv(): void
    {
        $dotenv = new Dotenv();
        $dotenv->usePutenv();
        $dotenv->load(__DIR__ . '/../.env.dist');

        if (is_file(__DIR__ . '/../.env.local')) {
            $dotenv->load(__DIR__ . '/../.env.local');
        }
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
        $containerBuilder->setParameter('kernel.project_dir', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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
