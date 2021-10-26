<?php

declare(strict_types=1);

namespace App\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

class Template
{
    public function __construct(
        string $template
    ) {
        $this->template = Yaml::parseFile();
    }

    public function getRequiredServices()
    {

    }

    public function getOptionalServices()
    {

    }

    public function load(string $template)
    {

    }
}
