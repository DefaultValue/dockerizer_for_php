<?php

declare(strict_types=1);

namespace App\Command\Composition;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowTemplates extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:show-templates';

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

    protected function configure(): void
    {
        $this->setDescription('Show list of available composition templates from `./templates/apps/`');

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->templateList->getTemplatesList() as $template => $files) {
            $output->writeln($template);

            foreach ($files as $file) {
                $output->writeln('  - ' . $file);
            }
        }

        return self::SUCCESS;
    }
}
