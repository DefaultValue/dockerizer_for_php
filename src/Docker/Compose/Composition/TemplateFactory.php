<?php

declare(strict_types=1);

namespace App\Docker\Compose\Composition;

class TemplateFactory
{
    // @TODO: validate template before loading it
//    private \Symfony\Component\DependencyInjection\ContainerInterface $container;
//
//    public function __construct(\Symfony\Component\DependencyInjection\ContainerInterface $container)
//    {
//        $this->container = $container;
//    }

    public function makeTemplate(string $templateName): Template
    {
        return new Template($templateName);
    }
}
