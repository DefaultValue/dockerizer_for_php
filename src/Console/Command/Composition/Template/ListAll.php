<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition\Template;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListAll extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:list-all';

    private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList;

    private string $compositionTemplatesDir;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList
     * @param string $compositionTemplatesDir
     * @param string|null $name
     */
    public function __construct(
        \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList,
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
        $output->writeln("Composition templates dir: <info>$this->compositionTemplatesDir</info>");

        foreach ($this->templateList->getTemplatesList() as $template => $file) {
            $output->writeln("<info>$template</info>: $file");
        }

        return self::SUCCESS;
    }
}
