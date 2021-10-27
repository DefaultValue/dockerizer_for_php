<?php

declare(strict_types=1);

namespace App\Command\Composition\Template;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Meta extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:meta';

    private \App\Docker\Compose\Composition\TemplateList $templateList;

    /**
     * @param \App\Docker\Compose\Composition\TemplateList $templateList
     * @param string|null $name
     */
    public function __construct(
        \App\Docker\Compose\Composition\TemplateList $templateList,
        string $name = null
    ) {
        parent::__construct($name);
        $this->templateList = $templateList;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Show template meta information');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // @TODO: Move hardcoded value to parameters
        $template = $this->templateList->getTemplate('magento_2.0.2-2.0.x.yaml');

        $output->writeln("Name: {$template->getName()}");

        return self::SUCCESS;
    }
}
