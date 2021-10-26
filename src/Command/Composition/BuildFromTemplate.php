<?php

declare(strict_types=1);

namespace App\Command\Composition;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildFromTemplate extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:build-from-template';

    private \App\Docker\Compose\Composition\TemplateFactory $templateFactory;

    public function __construct(
        \App\Docker\Compose\Composition\TemplateFactory $templateFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->templateFactory = $templateFactory;
    }

    protected function configure(): void
    {
        $this->setDescription('Create Docker composition from templates in `./templates/apps/`');

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @TODO: validate all recipes?
        $template = $this->templateFactory->makeTemplate('magento_2.0.2-2.0.x');

        return self::SUCCESS;
    }
}
