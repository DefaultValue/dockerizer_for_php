<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition\Template;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Meta extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:meta';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\Template\Collection $templateCollection
     * @param string|null $name
     */
    public function __construct(
        private Template\Collection $templateCollection,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        // @TODO: add ability to filter templates by name
        $this->setDescription('WIP: Show template meta information');
// --all to get all meta, filter to filter by app name and/or version
//        $this->addArgument()
//'version',
//InputArgument::REQUIRED,
//'Semantic Magento version like 2.2.10, 2.3.2 etc.'
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        $template = $this->templateList->getFile($input->getArgument());
        foreach ($this->templateCollection as $template) {
            $this->outputTemplateMeta($output, $template);
            $output->writeln(PHP_EOL . '---' . PHP_EOL);
        }

        return self::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @param Template $template
     */
    private function outputTemplateMeta(OutputInterface $output, Template $template): void
    {
        $output->writeln("<info>Template code:</info> {$template->getCode()}");
        $output->writeln("<info>Description:</info> {$template->getDescription()}");
        $output->writeln('<info>Supported apps:</info>');
    }
}
