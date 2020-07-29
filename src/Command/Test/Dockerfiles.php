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
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> sets up multiple Magento versions with the defined local Dockerfiles to test them.

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
        $this->log("Website address: https://$domain");
    }
}
