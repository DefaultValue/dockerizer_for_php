<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition;
//          DefaultValue\Dockerizer\Command\Composition\BuildFromTemplate
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:build-from-template';

    private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList;

    public function __construct(
        \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList,
        string $name = null
    ) {
        parent::__construct($name);
        $this->templateList = $templateList;
    }

    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates in `./templates/apps/`');

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @TODO: validate all recipes?
        // @TODO: Move hardcoded value to parameters
        $template = $this->templateList->getTemplate('magento_2.0.2-2.0.x.yaml');

        return self::SUCCESS;
    }
}
