<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\Question\Domains;
use App\Service\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dockerize the PHP project
 *
 * Class Dockerize
 */
class EnvAdd extends AbstractCommand
{
    public const ARGUMENT_ENVIRONMENT_NAME = 'environment_name';

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * @var \App\Service\FileProcessor
     */
    private $fileProcessor;

    /**
     * Dockerize constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param \App\Service\Filesystem $filesystem
     * @param \App\Service\FileProcessor $fileProcessor
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        $name = null
    ) {
        $this->filesystem = $filesystem;
        $this->fileProcessor = $fileProcessor;
        parent::__construct($env, $shell, $questionPool, $name);
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->setName('env:add')
            ->addArgument(
                self::ARGUMENT_ENVIRONMENT_NAME,
                InputArgument::REQUIRED,
                'Environment name'
            )->addOption(
                // Not really great to have this constant here
                SetUpMagento::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Overwrite environment file'
            )
            ->setDescription('<info>Dockerize existing PHP projects</info>')
            ->setHelp(<<<'EOF'
Copy Docker files to the current folder and update them as per project settings.
You will be asked to enter production domains, choose PHP version and web root folder.
You will be asked to add more environments for staging/text/development/etc. environments with the same or new domains.
If you made a mistype in the PHP version or domain names - re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name%</info>

Example usage with PHP version, MySQL container and with domains, without questions when possible
(non-interactive mode) and without adding more environments:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=7.3 --mysql-container=mysql57 --domains='example.com www.example.com' -n</info>

Magento 1 example with custom web root:

    <info>php /misc/apps/dockerizer_for_php/bin/console %command.full_name% --php=5.6 --mysql-container=mysql56 --domains='example.com www.example.com' --webroot='/'</info>

Docker containers are not run automatically, so you can still edit configurations before running them.
The file `/etc/hosts` is not populated automatically!

docker-sync is not processed. must be edited manually
EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    public function getQuestions(): array
    {
        return [
            Domains::QUESTION
        ];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // php /misc/apps/dockerizer_for_php/bin/console env:add staging --domains="test-2.local www.test-2.local" -f && cat docker-compose-staging.yml
        $exitCode = 0;

        try {
            $envName = $input->getArgument(self::ARGUMENT_ENVIRONMENT_NAME);

            // 1. Get env name. Ensure it does not exist proceed if -f
            $envFileName = "docker-compose-$envName.yml";

            if ($this->filesystem->isWritableFile($envFileName) && !$input->getOption(SetUpMagento::OPTION_FORCE)) {
                throw new \InvalidArgumentException(
                    "Environment file '$envFileName' already exists. Please, enter other env name, remove it or use -f"
                );
            }

            // 2. Get container name from the main file and domains from all files
            if (!preg_match('/container_name.*\n/', file_get_contents('docker-compose.yml'), $mainContainerName)) {
                throw new \RuntimeException('Can\'t find "container_name" in the "docker-compose.yml" file.');
            }

            $mainContainerName = trim(explode(':', $mainContainerName[0])[1]);
            $envContainerName = "$mainContainerName-$envName";
            $output->writeln(<<<EOF
            <info>Detected application container name: <fg=blue>$mainContainerName</fg=blue>
            Container name for the new environment: <fg=blue>$envContainerName</fg=blue></info>
            EOF);

            // 3. Get env domains
            /** @var Domains $domainsQuestion */
            $domains = $this->ask(Domains::QUESTION, $input, $output);
            $allDomainsIncludingExisting = [];

            // reverse array of files because 'docker-compose.yml' with main domain is the last one
            foreach (array_reverse(glob('docker-compose*yml')) as $file) {
                if (
                    preg_match(
                        '/traefik\.https\.frontend\.rule.*/i',
                        file_get_contents($file),
                        $traefikFrontendRules
                    )
                ) {
                    // must optimize this poor code and use better regexp :(
                    $frontendRuleDomains = explode(',', trim(explode(':', $traefikFrontendRules[0])[1]));
                    $allDomainsIncludingExisting[] = $frontendRuleDomains;
                }
            }

            $allDomainsIncludingExisting[] = $domains;
            $allDomainsIncludingExisting = array_unique(array_merge([], ...$allDomainsIncludingExisting));

            // 4. Copy docker-compose-dev.yml content
            $envTemplate = $this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE) . 'docker-compose-dev.yml';
            copy($envTemplate, $envFileName);

            // 5. generate new cert from all domains - do not remove old because other websites may use it
            $sslCertificateFiles = $this->filesystem->generateSslCertificates($allDomainsIncludingExisting);

            // 6. Update container name and configs
            $this->fileProcessor->processDockerComposeFiles(
                [
                    $envFileName
                ],
                [
                    'example-dev.com,www.example-dev.com,example-2-dev.com,www.example-2-dev.com',
                    'example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com',
                    'example.com',
                ],
                $domains,
                $envContainerName
            );

            // 7. Update virtual_host.conf and .htaccess
            $this->fileProcessor->processVirtualHostConf(
                ['docker/virtual-host.conf'],
                $allDomainsIncludingExisting,
                $sslCertificateFiles
            );
            $this->fileProcessor->processHtaccess([$envFileName]);

            // 8. Update traefik conf
            $this->fileProcessor->processTraefikRules($sslCertificateFiles);

            // 9. Update /etc/hosts file
            $this->fileProcessor->processHosts($domains);
        } catch (\Exception $e) {
            $exitCode = 1;
            $output->writeln("<error>{$e->getMessage()}</error>");
        }

        return $exitCode;
    }
}
