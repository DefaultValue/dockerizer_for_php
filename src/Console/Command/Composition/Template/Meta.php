<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Composition\Template;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;

class Meta extends \Symfony\Component\Console\Command\Command
{
    protected static $defaultName = 'composition:template:meta';

    private \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList
     * @param string|null $name
     */
    public function __construct(
        \DefaultValue\Dockerizer\Docker\Compose\Composition\TemplateList $templateList,
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
        $template = $this->templateList->getTemplate('magento_2.0.2-2.0.x.yaml');

        $output->writeln("<info>Name:</info> {$template->getName()}");

        $output->writeln('<info>Supported apps:</info>');

        foreach ($template->getSupportedPackages() as $package => $versionInfo) {
            $output->writeln(sprintf(
                '- %s: >=%s - <%s',
                $package,
                $versionInfo[Template::SUPPORTED_PACKAGE_EQUALS_OR_GREATER],
                $versionInfo[Template::SUPPORTED_PACKAGE_LESS_THAN]
            ));
        }

        $output->writeln('<info>Runners (main service to run application):</info>');

        foreach ($template->getRunners() as $runner) {
            $output->writeln("- $runner");
        }

        return self::SUCCESS;
    }
}
