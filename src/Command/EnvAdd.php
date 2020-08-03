<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Magento\SetUp;
use App\CommandQuestion\Question\Domains;
use App\CommandQuestion\Question\MysqlContainer;
use App\CommandQuestion\Question\PhpVersion;
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
     * @param ?string $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        \App\Service\Filesystem $filesystem,
        \App\Service\FileProcessor $fileProcessor,
        ?string $name = null
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
                SetUp::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Overwrite environment file'
            )
            ->setDescription('<info>Add new docker infrastructure</info>')
            ->setHelp(<<<'EOF'
We often need more then just a production environment - staging, test, development etc. Use the following command to
add more environments to your project:

    <info>php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/console %command.full_name% <env_name></info>

This will:
- copy the <fg=blue>docker-compose.yml</fg=blue> template and rename it (for example, to <fg=blue>docker-compose-staging.yml</fg=blue>);
- modify the <fg=blue>mkcert</fg=blue> information string in the <fg=blue>docker-compose.file</fg=blue>;
- generate new SSL certificates for all domains from the <fg=blue>docker-compose*.yml</fg=blue> files;
- reconfigure <fg=blue>Traefik</fg=blue> and <fg=blue>virtual-host.conf</fg=blue>, update <fg=blue>.htaccess</fg=blue>;
- add new entries to the <fg=blue>/etc/hosts</fg=blue> file if needed.

Container name is based on the main (actually, the first) container name from the <fg=blue>docker-compose.yml</fg=blue>
file suffixed with the <fg=blue>-<env_name></fg=blue>. This allows running multiple environments at the same time.

Composition is not restarted automatically, so you can edit everything finally running it.

<fg=red>CAUTION!</fg=red>

1) SSL certificates are not specially prefixed! If you add two environments in different folders (let's say
<fg=blue>dev</fg=blue> and <fg=blue>staging</fg=blue>) then the certificates will be overwritten for one of them.
Instead of manually configuring the certificates you can first copy new <fg=blue>docker-compose.yml</fg=blue>
to the folder where you're going to add new <fg=blue>staging</fg=blue> environment.

2) If your composition runs other named services (e.g., those that have <fg=blue>container_name</fg=blue>)
then you'll have to rename them manually by moving those services to the new environment file and changing
the container name like this is done for the PHP container. You're welcome to automate this as well.

EOF);

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function getQuestions(): array
    {
        return [
            PhpVersion::QUESTION,
            MysqlContainer::QUESTION,
            Domains::QUESTION
        ];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = 0;

        try {
            $envName = $input->getArgument(self::ARGUMENT_ENVIRONMENT_NAME);

            // 1. Get env name. Ensure it does not exist proceed if -f
            $envFileName = "docker-compose-$envName.yml";

            if ($this->filesystem->isWritableFile($envFileName) && !$input->getOption(SetUp::OPTION_FORCE)) {
                throw new \InvalidArgumentException(
                    "Environment file '$envFileName' already exists. Please, enter other env name, remove it or use -f"
                );
            }

            // 2. Get container name from the main file and domains from all files
            if (
                !preg_match(
                    '/container_name.*\n/',
                    (string) file_get_contents('docker-compose.yml'),
                    $mainContainerName
                )
            ) {
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
                        '/traefik\.http\.routers.*/i',
                        file_get_contents($file),
                        $traefikFrontendRules
                    )
                ) {
                    // must optimize this poor code and use better regexp :(
                    $frontendRuleDomains = explode('`,`', trim(explode('Host(', $traefikFrontendRules[0])[1], '`()'));
                    $allDomainsIncludingExisting[] = $frontendRuleDomains;
                }
            }

            $allDomainsIncludingExisting[] = $domains;
            $allDomainsIncludingExisting = array_unique(array_merge([], ...$allDomainsIncludingExisting));

            // 4. Copy docker-compose.yml content
            $envTemplate = $this->filesystem->getDirPath(Filesystem::DIR_PROJECT_TEMPLATE) . 'docker-compose.yml';
            copy($envTemplate, $envFileName);

            // 5. Generate new cert from all domains - do not remove old because other websites may use it
            $sslCertificateFiles = $this->filesystem->generateSslCertificates($allDomainsIncludingExisting);

            // 6. Update container name and configs
            $this->fileProcessor->processDockerCompose(
                [$envFileName],
                $domains,
                $envContainerName,
                $this->ask(MysqlContainer::QUESTION, $input, $output),
                $this->ask(PhpVersion::QUESTION, $input, $output)
            );

            // 7. Update virtual_host.conf and .htaccess, do not change web root
            $this->fileProcessor->processVirtualHostConf(
                ['docker/virtual-host.conf'],
                $allDomainsIncludingExisting,
                $sslCertificateFiles,
                '',
                false
            );
            $this->fileProcessor->processMkcertInfo($allDomainsIncludingExisting);
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
