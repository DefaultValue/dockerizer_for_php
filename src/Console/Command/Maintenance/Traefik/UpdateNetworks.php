<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Maintenance\Traefik;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinition\Docker\Container as CommandOptionDockerContainer;
use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;
use DefaultValue\Dockerizer\Docker\Events;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * @noinspection PhpUnused
 */
class UpdateNetworks extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand implements
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    protected static $defaultName = 'maintenance:traefik:update-networks';

    protected array $commandSpecificOptions = [
        CommandOptionDockerContainer::OPTION_NAME
    ];

    // @TODO: remember containers in the same network and unset data on disconnection
    /** @var string[] $killedContainers */
    private array $killedContainers = [];

    /**
     * @param \DefaultValue\Dockerizer\Docker\Container $dockerContainer
     * @param \DefaultValue\Dockerizer\Docker\Network $dockerNetwork
     * @param \DefaultValue\Dockerizer\Docker\Events $dockerEvents
     * @param iterable<OptionDefinitionInterface> $availableCommandOptions
     * @param string|null $name
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Container $dockerContainer,
        private \DefaultValue\Dockerizer\Docker\Network $dockerNetwork,
        private \DefaultValue\Dockerizer\Docker\Events $dockerEvents,
        iterable $availableCommandOptions,
        string $name = null
    ) {
        parent::__construct($availableCommandOptions, $name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->setDescription('Watch for Docker network events and connect/disconnect proxy')
//            ->setHelp(<<<'EOF'
//
//            EOF)
            ->addOption(
                'watch',
                'w',
                \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
                'Watch for Docker contatiner/network events, and connect/disconnect proxy'
            );
        // phpcs:enable

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proxyContainerName = $this->getCommandSpecificOptionValue(
            $input,
            $output,
            CommandOptionDockerContainer::OPTION_NAME
        );
        $output->writeln('Proxy container name: ' . $proxyContainerName);

        $this->disconnectFromEmptyNetworks($proxyContainerName, $output);
        $this->connectToNetworks($proxyContainerName, $output);

        if ($input->getOption('watch')) {
            // Handle container connecting to a new network: connect proxy to the same network
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($proxyContainerName, $output) {
                    $this->connectToNetworks($proxyContainerName, $output, trim($eventsDataJson));
                },
                ['type=' . Events::EVENT_TYPE_NETWORK, 'event=connect']
            );
            // Handle container kill
            // Disconnect container from the network and remove empty networks if none containers are running in it
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($proxyContainerName, $output) {
                    $this->disconnectIfContainerKilled($proxyContainerName, $output, trim($eventsDataJson));
                },
                ['type=' . Events::EVENT_TYPE_CONTAINER, 'event=kill']
            );
            $this->dockerEvents->watch();
        }

        return self::SUCCESS;
    }

    /**
     * NOTE! For now, we don't check whether any container have `traefik.enable=true` label
     * This is done to avoid issue when `providers.docker.exposedbydefault=true`
     *
     * @param string $proxyContainerName
     * @param OutputInterface $output
     * @param string $eventsDataJson
     * @return void
     * @throws \JsonException
     */
    private function connectToNetworks(
        string $proxyContainerName,
        OutputInterface $output,
        string $eventsDataJson = ''
    ): void {
        if (!($proxyContainerId = $this->getProxyContainerId($proxyContainerName, $output))) {
            return;
        }

        foreach ($this->getNetworkEventNetworks($proxyContainerId, $eventsDataJson) as $network) {
            if (str_contains($network, $proxyContainerName)) {
                continue;
            }

            $networkContainers = $this->dockerNetwork->inspectJsonWithDecode($network, '{{json .Containers}}');

            // @TODO: Check the `traefik.docker.network` label and connect traefik only to this network
            if ($networkContainers && !isset($networkContainers[$proxyContainerId])) {
                $output->writeln('Connecting proxy to network: ' . $network);
                $this->dockerNetwork->connect($network, $proxyContainerName);
            }
        }
    }

    /**
     * Disconnect proxy from empty networks (i.e., networks with only proxy container)
     * And remove empty networks
     * NOTE! For now, we don't check whether any container have `traefik.enable=true` label
     *
     * @param string $proxyContainerName
     * @param OutputInterface $output
     * @return void
     * @throws \JsonException
     */
    private function disconnectFromEmptyNetworks(
        string $proxyContainerName,
        OutputInterface $output
    ): void {
        if (!($proxyContainerId = $this->getProxyContainerId($proxyContainerName, $output))) {
            return;
        }

        foreach ($this->getNetworkEventNetworks($proxyContainerId, '') as $network) {
            if (str_contains($network, $proxyContainerName)) {
                continue;
            }

            try {
                $networkContainers = $this->dockerNetwork->inspectJsonWithDecode($network, '{{json .Containers}}');
            } catch (ProcessFailedException) {
                $output->writeln(sprintf('Network %s not found, skipping', $network));
                continue;
            }

            if (
                count($networkContainers) === 1
                && isset($networkContainers[$proxyContainerId])
            ) {
                $this->disconnect($output, $network, $proxyContainerName);
            }
        }
    }

    /**
     * Disconnect proxy from the network if container was killed.
     * Memorize killed containers in case Docker composition container multiple containers,
     * and we receive multiple events for different containers as different calls to this method.
     *
     * @param string $proxyContainerName
     * @param OutputInterface $output
     * @param string $eventsDataJson
     * @return void
     * @throws \JsonException
     */
    private function disconnectIfContainerKilled(
        string $proxyContainerName,
        OutputInterface $output,
        string $eventsDataJson
    ): void {
        if (!($proxyContainerId = $this->getProxyContainerId($proxyContainerName, $output))) {
            return;
        }

        $output->writeln($eventsDataJson);
        $output->writeln('');
        $networksToCheck = [];

        // Get networks with both proxy and killed container
        foreach (explode(PHP_EOL, $eventsDataJson) as $eventDataJson) {
            $eventData = json_decode($eventDataJson, true, 512, JSON_THROW_ON_ERROR);
            $containerName = $eventData['Actor']['Attributes']['name'];

            if ($containerName === $proxyContainerName) {
                continue;
            }

            $networksToCheck[] = $this->getContainerNetworks($containerName);
            $this->killedContainers[] = substr($eventData['Actor']['ID'], 0, 12);
        }

        $networksToCheck = array_unique(array_merge(...$networksToCheck));
        $reverseProxyNetworks = $this->getContainerNetworks($proxyContainerName);
        $networksWithKilledContainer = array_intersect($networksToCheck, $reverseProxyNetworks);

        if (!$networksWithKilledContainer) {
            return;
        }

        $output->writeln('Networks to check for proxy disconnection: ' . implode(', ', $networksWithKilledContainer));

        foreach ($networksWithKilledContainer as $network) {
            $networkContainersData = array_keys($this->dockerNetwork->inspectJsonWithDecode(
                $network,
                '{{json .Containers}}'
            ));

            $networkContainers = array_map(
                static fn (string $containerId) => substr($containerId, 0, 12),
                $networkContainersData
            );

            // Remember that `docker ps` returns only partial container ID instead of the full hash
            // Let's try running `docker ps` to get fresh data instead of remembering it
            $runningContainerIds = array_map(
                static fn (array $item) => $item['ID'],
                $this->dockerContainer->ps()
            );

            $runningNetworkContainers = array_intersect($runningContainerIds, $networkContainers);
            $nonKilledNetworkContainers = array_diff(
                $runningNetworkContainers,
                $this->killedContainers,
                [substr($proxyContainerId, 0, 12)]
            );

            if ($nonKilledNetworkContainers) {
                $output->writeln('Running containers: ' . implode(', ', $nonKilledNetworkContainers));
            } else {
                $this->disconnect($output, $network, $proxyContainerName);
            }
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('');
    }

    /**
     * @param OutputInterface $output
     * @param string $network
     * @param string $proxyContainerName
     * @return void
     */
    private function disconnect(
        OutputInterface $output,
        string $network,
        string $proxyContainerName
    ): void {
        try {
            $output->writeln('Removing proxy from network: ' . $network);
            $this->dockerNetwork->disconnect($network, $proxyContainerName);
        } catch (ProcessFailedException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
    }


    /**
     * @param string $proxyContainerName
     * @param OutputInterface $output
     * @return string
     */
    private function getProxyContainerId(string $proxyContainerName, OutputInterface $output): string
    {
        // Skip in case container is not running
        try {
            return $this->dockerContainer->inspect($proxyContainerName, '{{index .Id}}');
        } catch (ProcessFailedException) {
            $output->writeln(sprintf('Proxy container %s is not running, skipping', $proxyContainerName));
        }

        return '';
    }

    /**
     * Get all networks except `bridge`, `host` and `none` from the events data
     * Or return all networks if events data is empty
     *
     * @param string $proxyContainerId
     * @param string $eventsDataJson
     * @return string[]
     * @throws \JsonException
     */
    private function getNetworkEventNetworks(string $proxyContainerId, string $eventsDataJson): array
    {
        // Get all networks in case no event data provided
        if (!$eventsDataJson) {
            return array_diff($this->dockerNetwork->ls(), ['bridge', 'host', 'none']);
        }

        // Not sure why this happens, but we need to ignore bridge network
        $bridgeNetworkId = $this->dockerNetwork->inspect('bridge', '{{index .Id}}');
        $networks = [];

        foreach (explode(PHP_EOL, $eventsDataJson) as $eventDataJson) {
            $eventData = json_decode($eventDataJson, true, 512, JSON_THROW_ON_ERROR);

            if ($eventData['Type'] !== Events::EVENT_TYPE_NETWORK) {
                throw new \InvalidArgumentException('Only network events are supported');
            }

            // Ignore removing proxy itself from the network
            if ($eventData['Actor']['Attributes']['container'] === $proxyContainerId) {
                continue;
            }

            $networks[] = $eventData['Actor']['ID'];
        }

        $networks = array_diff(array_unique($networks), [$bridgeNetworkId]);

        return array_diff($networks, ['bridge', 'host', 'none']);
    }

    /**
     * @param string $containerName
     * @return string[]
     * @throws \JsonException
     */
    private function getContainerNetworks(string $containerName): array
    {
        $networks = array_keys($this->dockerContainer->inspectJsonWithDecode(
            $containerName,
            '{{json .NetworkSettings.Networks}}'
        ));

        return array_diff($networks, ['bridge', 'host', 'none']);
    }
}
