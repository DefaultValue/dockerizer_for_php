<?php

declare(strict_types=1);

namespace App\Command\Test;

class Dockerfiles extends AbstractMultithreadTest
{
    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('test:dockerfiles')
            ->setDescription('<info>Install Magento with local Dockerfiles to test them after changes are made</info>')
            ->setHelp(<<<'EOF'
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
     * @param string $phpVersion
     * @param string $magentoVersion
     * @throws \JsonException
     */
    protected function installMagento(string $domain, string $phpVersion, string $magentoVersion): void
    {
        throw new \RuntimeException('In development');
        $this->shell->exec(<<<BASH
            php {$this->getDockerizerPath()} magento:setup $magentoVersion \
                --domains="$domain www.$domain" --php=$phpVersion -nf
        BASH);
        $this->shell->exec(
            <<<BASH
                git add .gitignore .htaccess docker* var/log/ app/
                git commit -m "Docker and Magento files after installation" -q
            BASH,
            $projectRoot
        );

        $this->shell->dockerExec('php bin/magento sampledata:deploy', $domain);
        $this->shell->dockerExec('php bin/magento setup:upgrade', $domain);
        $this->shell->dockerExec('php bin/magento deploy:mode:set production', $domain);

        $this->log("Website address: https://$domain");
    }
}
