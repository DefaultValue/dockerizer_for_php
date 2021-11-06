<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'composition:build-from-template';

//    private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList;

//    public function __construct(
//        \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList,
//        string $name = null
//    ) {
//        parent::__construct($name);
//        $this->templateList = $templateList;
//    }

    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates in `./templates/apps/`');

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @TODO: validate all recipes?
        // @TODO: Move hardcoded value to parameters
        // @TODO: get packages from composer.json, ask for confirm if package version does not match supported versions
        $template = $this->templateList->getTemplate('magento_2.0.2-2.0.x.yaml');

        return self::SUCCESS;
    }
}
