<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition\Template;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;

class Meta extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:meta';

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateCollection $templateCollection
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateCollection $templateCollection,
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
        $this->setDescription('Show template meta information');
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
    public function execute(InputInterface $input, OutputInterface $output): int
    {
//        $template = $this->templateList->getTemplate($input->getArgument());
        foreach ($this->templateCollection->getTemplates() as $template) {
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
        $output->writeln("<info>Name:</info> {$template->getName()}");

        $output->writeln('<info>Supported apps:</info>');

        foreach ($template->getSupportedPackages() as $package => $versionInfo) {
            $output->writeln(sprintf(
                '  - %s: >=%s - <%s',
                $package,
                $versionInfo[Template::SUPPORTED_PACKAGE_EQUALS_OR_GREATER],
                $versionInfo[Template::SUPPORTED_PACKAGE_LESS_THAN]
            ));
        }

        $output->writeln('<info>Runners (main service to run application):</info>');

        foreach ($template->getRunners() as $runner) {
            $output->writeln("  - $runner");
        }
    }
}
