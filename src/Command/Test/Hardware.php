<?php

declare(strict_types=1);

namespace App\Command\Test;

class Hardware extends AbstractMultithreadTest
{
    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('test:hardware')
            ->setDescription('<info>Install Magento packed inside the Docker container</info>')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> sets up Magento and perform a number of tasks to test environment:
- install Magento 2 (2.0.18 > PHP 5.6, 2.1.18 > PHP 7.0, 2.2.11 > PHP 7.1, 2.3.2 > PHP 7.2, 2.3.4 > PHP 7.3);
- commit Docker files;
- test Dockerizer's <fg=blue>env:add</fg=blue> - stop containers, dockerize with another domains,
  add env, and run composition;
- run <fg=blue>deploy:mode:set production</fg=blue>;
- run <fg=blue>setup:perf:generate-fixtures</fg=blue> to generate data for performance testing
  (medium size profile for v2.2.0+, small for previous version because generating data takes too much time);
- run <fg=blue>indexer:reindex</fg=blue>.

Usage for hardware test and Dockerizer self-test (install all instances and ensure they work fine):

    <info>php bin/console %command.full_name%</info>

Log files are written to <fg=blue>dockerizer_for_php/var/hardware_test_results/</fg=blue>.

@TODO:
- render 5 pages for 20 times;
- generate CSS files for 10-20 times.

EOF);
    }

    /**
     * @return callable[]
     */
    protected function getCallbacks(): array
    {
        return [
            [$this, 'installMagento'],
            [$this, 'runTests'],
        ];
    }

    /**
     * @param string $domain
     * @param string $phpVersion
     * @param string $magentoVersion
     * @throws \JsonException
     * @throws \RuntimeException
     */
    protected function installMagento(string $domain, string $phpVersion, string $magentoVersion): void
    {
        $projectRoot = $this->env->getProjectsRootDir() . $domain;
        $malformedDomain = str_replace('.local', '-2.local', $domain);

        $this->log("Installing Magento for the domain $domain");
        $this->shell->exec(<<<BASH
            php {$this->getDockerizerPath()} magento:setup $magentoVersion \
                --domains="$domain www.$domain" --php=$phpVersion -nf
        BASH);

        $this->log("Adding staging environment for the domain $domain");
        $this->shell->exec(
            <<<BASH
                git add .gitignore .htaccess docker* var/log/ app/
                git commit -m "Docker and Magento files after installation" -q
                docker-compose down
                rm -rf docker*
                php {$this->getDockerizerPath()} dockerize -n \
                    --domains="$malformedDomain www.$malformedDomain" \
                    --php=$phpVersion
                php {$this->getDockerizerPath()} env:add staging --domains="$domain www.$domain" --php=$phpVersion -nf
                docker-compose -f docker-compose-staging.yml up -d --force-recreate --build
            BASH,
            $projectRoot
        );
        $this->log("Launched composition for the domain $domain with the staging env");

        // Wait till Traefik starts proxying this host
        $retries = 10;
        // Malformed domain is used as a container and router name
        $traefikRouterName = str_replace('.', '-', $malformedDomain) . '-staging-http' . '@docker';
        $traefikRouterFound = false;

        while ($retries) {
            if ($routerInfo = file_get_contents("http://traefik.docker.local/api/http/routers/$traefikRouterName")) {
                $routerInfo = json_decode($routerInfo, true, 512, JSON_THROW_ON_ERROR);
            }

            if (is_array($routerInfo) && isset($routerInfo['service'])) {
                $traefikRouterFound = true;
                break;
            }

            --$retries;
            sleep(1);
        }

        if (!$traefikRouterFound) {
            throw new \RuntimeException("Traefik backend not found for $domain");
        }

        $content = strtolower(file_get_contents("https://$domain"));

        if (strpos($content, 'home page') === false) {
            throw new \RuntimeException("Composition is not running for $domain");
        }

        // We've changed main domain and added staging env, so here is the current container name:
        $containerName = "$malformedDomain-staging";
        $this->shell->exec("docker exec $containerName php bin/magento setup:upgrade");
    }

    /**
     * @param string $domain
     * @param string $phpVersion
     * @param string $magentoVersion
     */
    protected function runTests(string $domain, string $phpVersion, string $magentoVersion): void
    {
        $malformedDomain = str_replace('.local', '-2.local', $domain);
        // We've changed main domain and added staging env, so here is the current container name:
        $containerName = "$malformedDomain-staging";
        $this->log("Executing 'deploy:mode:set production' for the domain $domain");
        $this->execWithTimer("docker exec $containerName php bin/magento deploy:mode:set production");

        // Generate fixtures for performance testing. Medium size profile takes too long to execute on the old M2
        $this->log("Executing 'setup:perf:generate-fixtures' for the domain $domain");
        $profileSize  = version_compare($magentoVersion, '2.2.0', '<') ? 'small' : 'medium';
        $this->execWithTimer(
            "docker exec $containerName php bin/magento setup:perf:generate-fixtures " .
            "/var/www/html/setup/performance-toolkit/profiles/ce/$profileSize.xml"
        );

        $this->log("Executing 'indexer:reindex' for the domain $domain");
        $this->execWithTimer("docker exec $containerName php bin/magento indexer:reindex");

        // @TODO: add test to curl pages; add tests to build less files
        $this->log("Website address: https://$domain");
    }
}
