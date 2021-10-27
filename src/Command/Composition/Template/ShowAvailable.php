<?php

declare(strict_types=1);

namespace App\Command\Composition\Template;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowAvailable extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:show-available';

    private \App\Docker\Compose\Composition\TemplateList $templateList;

    private string $compositionTemplatesDir;

    /**
     * @param \App\Docker\Compose\Composition\TemplateList $templateList
     * @param string $compositionTemplatesDir
     * @param string|null $name
     */
    public function __construct(
        \App\Docker\Compose\Composition\TemplateList $templateList,
        string $compositionTemplatesDir,
        string $name = null
    ) {
        $this->templateList = $templateList;
        $this->compositionTemplatesDir = $compositionTemplatesDir;
        parent::__construct($name);
    }

    /**
     * @TODO: all "like" and/or "regex" parameter for filtering templates
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription(
            'Show list of available composition templates from <info>' . $this->compositionTemplatesDir . '</info>'
        );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Composition templates dir: ' . $this->compositionTemplatesDir);

        foreach ($this->templateList->getTemplatesList() as $template => $file) {
            $output->writeln("$template: $file");
        }

        return self::SUCCESS;
    }
}
