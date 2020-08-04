<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Command\Dockerize;
use Symfony\Component\Console\Input\InputOption;

class Dockerfiles extends AbstractMultithreadTest
{
    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('test:dockerfiles')
            ->setDescription('<info>Install Magento with local Dockerfiles to test them after changes are made</info>')
            ->addOption(
                Dockerize::OPTION_EXECUTION_ENVIRONMENT,
                'e',
                InputOption::VALUE_REQUIRED,
                'Use local Dockerfile from the Docker Infrastructure repository instead of the prebuild DockerHub image'
            )->setHelp(<<<'EOF'
The <info>%command.name%</info> sets up multiple Magento versions with the defined local Dockerfiles to test them.
Includes installing Sample Data and reindex.

EOF);
    }

    /**
     * @return callable[]
     */
    protected function getCallbacks(): array
    {
        return [
            [$this, 'installMagento'],
        ];
    }

    /**
     * @param string $domain
     * @param string $magentoVersion
     * @param string $phpVersion
     */
    protected function installMagento(string $domain, string $magentoVersion, string $phpVersion): void
    {
        $projectRoot = $this->env->getProjectsRootDir() . $domain;
        $executionEnvironment = $this->input->getOption(Dockerize::OPTION_EXECUTION_ENVIRONMENT) ?: 'development';

        $this->shell->exec(<<<BASH
            php {$this->getDockerizerPath()} magento:setup $magentoVersion \
                --domains="$domain www.$domain" \
                --php=$phpVersion \
                --execution-environment=$executionEnvironment \
                -nf
        BASH);
        $this->shell->exec(
            <<<BASH
                git add -A
                git commit -m "Initial commit (with Docker infrastructure)" -q
            BASH,
            $projectRoot,
            true
        );

        $this->execWithTimer("docker exec $domain php bin/magento sampledata:deploy 2>/dev/null", true);
        $this->execWithTimer("docker exec $domain php bin/magento setup:upgrade");
        $this->execWithTimer("docker exec $domain php bin/magento deploy:mode:set developer");
        $this->execWithTimer("docker exec $domain php bin/magento indexer:reindex");

        $this->log("Website address: https://$domain");
    }
}
