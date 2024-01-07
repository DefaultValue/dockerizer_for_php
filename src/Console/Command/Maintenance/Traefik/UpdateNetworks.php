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
 * Connect/disconnect Traefik proxy to/from Docker networks
 * Watch for Docker network events and connect/disconnect proxy
 *
 * Docker container stays attached to the network even after it's stopped.
 * Thus, we need to disconnect proxy from the before that.
 * Otherwise, calling `docker-compose down` will fail with the `network ... has active endpoints` error.
 * This is why this commands has state to remember networks state and react to the early events
 * like `kill` instead of `destroy`. We don't have time to react to the `destroy` event on the `exited` container.
 *
 * @noinspection PhpUnused
 */
class UpdateNetworks extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand implements
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    protected static $defaultName = 'maintenance:traefik:update-networks';

    /**
     * @inheritdoc
     */
    protected array $commandSpecificOptions = [
        CommandOptionDockerContainer::OPTION_NAME
    ];

    /**
     * @var array<string, array<string, string>> $networkContainers
     */
    private array $networkContainers = [];

    /**
     * @var string $reverseProxyContainerName
     */
    private string $reverseProxyContainerName;

    /**
     * @var string $reverseProxyContainerId
     */
    private string $reverseProxyContainerId;

    /**
     * @var string $reverseProxyTruncatedNetworkId
     */
    private string $reverseProxyTruncatedNetworkId;

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
        $this->setDescription('Connect or disconnect Traefik proxy to/from Docker networks')
            ->setHelp(<<<'EOF'
                Connect/disconnect Traefik proxy to/from Docker networks:
                    <info>php -d xdebug.mode=off %command.full_name% -c reverse-proxy</info>

                Watch for Docker network events and connect/disconnect proxy:
                    <info>php -d xdebug.mode=off %command.full_name% -c reverse-proxy --watch</info>

                Debug mode to view events data:
                    <info>php -d xdebug.mode=off %command.full_name% -c reverse-proxy --watch -vvv</info>
                EOF)
            ->addOption(
                'watch',
                'w',
                \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
                'Watch for Docker contatiner/network events, and connect/disconnect proxy'
            );

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
        $output->writeln('Reverse-proxy container name: ' . $proxyContainerName);
        // Get container ID and save it because we have to time to dynamically get it on each event
        // @TODO: Watch killing this container and try refreshing the state or exit after a few retires
        $proxyContainerId = $this->dockerContainer->inspect($proxyContainerName, '{{index .Id}}');
        $output->writeln('Reverse-proxy container ID: ' . $proxyContainerId);
        $this->reverseProxyContainerName = $proxyContainerName;
        $this->reverseProxyContainerId = $proxyContainerId;

        $reverseProxyTruncatedNetworkId = array_filter(
            $this->dockerNetwork->ls(),
            static fn (string $networkName) => str_contains($networkName, $proxyContainerName)
                && str_ends_with($networkName, 'default')
        );

        if (count($reverseProxyTruncatedNetworkId) > 1) {
            throw new \RuntimeException('Reverse-proxy default network was not found');
        } elseif (count($reverseProxyTruncatedNetworkId) === 1) {
            $this->reverseProxyTruncatedNetworkId = substr(array_key_first($reverseProxyTruncatedNetworkId), 0, 12);
        } else {
            $this->reverseProxyTruncatedNetworkId = '';
        }

        $output->writeln('Collecting information about networks and containers...' . PHP_EOL);
        $this->initNetworksState($output);

        $output->writeln(sprintf('Disconnecting "%s" from empty networks...' . PHP_EOL, $proxyContainerName));
        $this->disconnectProxyFromEmptyNetworks($output);

        $output->writeln(sprintf('Connecting "%s" to non-empty networks...' . PHP_EOL, $proxyContainerName));
        $this->connectProxyToNonEmptyNetworks($output);

        if ($input->getOption('watch')) {
            // Handle connecting container to a network: connect proxy to the same network
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($output) {
                    $this->handleNetworkConnectEvent($output, trim($eventsDataJson));
                },
                ['type=' . Events::EVENT_TYPE_NETWORK, 'event=connect']
            );
            // Handle container kill: disconnect proxy from the network if container was killed
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($output) {
                    $this->handleContainerKillEvent($output, trim($eventsDataJson));
                },
                ['type=' . Events::EVENT_TYPE_CONTAINER, 'event=kill']
            );
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($output) {
                    $this->handleContainerKillEvent($output, trim($eventsDataJson));
                },
                ['type=' . Events::EVENT_TYPE_CONTAINER, 'event=die']
            );
            $this->dockerEvents->watch();
        }

        // Refresh state once more to react to the changes that appear during the command execution
        $this->initNetworksState($output);

        return self::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @return void
     * @throws \JsonException
     */
    private function initNetworksState(OutputInterface $output): void
    {
        foreach ($this->dockerNetwork->ls() as $truncatedNetworkId => $networkName) {
            if (!$this->canHandleThisNetwork($networkName)) {
                continue;
            }

            // Skip reverse-proxy default network
            if ($truncatedNetworkId === $this->getReverseProxyTruncatedNetworkId()) {
                continue;
            }

            $networkContainers = $this->dockerNetwork->inspectJsonWithDecode($networkName, '{{json .Containers}}');
            // Do not request information multiple times
            $processedContainers = [];

            foreach ($networkContainers as $containerId => $containerData) {
                if (isset($processedContainers[$containerId])) {
                    $traefikEnable = $processedContainers[$containerId];
                } else {
                    $traefikEnable = $this->dockerContainer->inspect(
                        $containerId,
                        '{{index .Config.Labels "traefik.enable"}}'
                    );
                    $processedContainers[$containerId] = $traefikEnable === 'true';
                }

                if ($traefikEnable) {
                    $this->networkContainers[$truncatedNetworkId] ??= [];
                    $this->networkContainers[$truncatedNetworkId][$containerId] = $containerData['Name'];
                }
            }

            if (!($this->networkContainers[$truncatedNetworkId] ?? [])) {
                $output->writeln(sprintf(
                    'Network "%s" (%s) does not contain containers with the label "traefik.enable=true"',
                    $networkName,
                    $truncatedNetworkId
                ));
                $output->writeln('');
            } else {
                $output->writeln(sprintf('Network "%s" (%s) containers:', $networkName, $truncatedNetworkId));

                foreach ($this->networkContainers[$truncatedNetworkId] as $containerId => $containerName) {
                    $output->writeln(sprintf('- %s (%s)', $containerName, $containerId));
                }

                $output->writeln('');
            }
        }
    }

    /**
     * @param string $networkName
     * @return bool
     */
    private function canHandleThisNetwork(string $networkName): bool
    {
        return !in_array($networkName, ['bridge', 'host', 'none']);
    }

    /**
     * @param OutputInterface $output
     * @param string $eventsDataJson
     * @return void
     * @throws \JsonException
     */
    private function handleNetworkConnectEvent(
        OutputInterface $output,
        string $eventsDataJson
    ): void {
        $output->writeln(PHP_EOL . 'Received event data:', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln($eventsDataJson . PHP_EOL, OutputInterface::VERBOSITY_VERBOSE);
        $proxyContainerId = $this->getProxyContainerId();
        // Do not request information multiple times
        $processedContainers = [];

        foreach (explode(PHP_EOL, $eventsDataJson) as $eventDataJson) {
            try {
                $eventData = json_decode($eventDataJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $output->writeln('Failed to decode event data: ' . $eventDataJson . PHP_EOL);
                $output->writeln($e->getMessage() . PHP_EOL);

                continue;
            }

            $truncatedNetworkId = substr($eventData['Actor']['ID'], 0, 12);
            $containerId = (string) $eventData['Actor']['Attributes']['container'];

            if (
                $containerId === $proxyContainerId
                || $truncatedNetworkId === $this->getReverseProxyTruncatedNetworkId()
            ) {
                continue;
            }

            try {
                if (isset($processedContainers[$containerId])) {
                    $traefikEnable = $processedContainers[$containerId];
                } else {
                    $traefikEnable = $this->dockerContainer->inspect(
                        $containerId,
                        '{{index .Config.Labels "traefik.enable"}}'
                    );
                    $processedContainers[$containerId] = $traefikEnable === 'true';
                }

                if ($traefikEnable) {
                    $containerName = trim('/', $this->dockerContainer->inspect($containerId, '{{index .Name}}'));
                    $this->networkContainers[$truncatedNetworkId] ??= [];
                    $this->networkContainers[$truncatedNetworkId][$containerId] = $containerName;
                }
            } catch (ProcessFailedException $e) {
                $output->writeln('Failed to get container name: ' . $containerId);
                $output->writeln('Container is probably dead' . PHP_EOL);
                $output->writeln($e->getMessage() . PHP_EOL);
            }

            // Connect proxy to the network ASAP and only once
            if (count($this->networkContainers[$truncatedNetworkId] ?? []) === 1) {
                $this->connectProxyToNonEmptyNetworks($output);
            }
        }
    }

    /**
     * Disconnect proxy from the network if container was killed.
     * Memorize killed containers in case Docker composition container multiple containers,
     * and we receive multiple events for different containers as different calls to this method.
     *
     * @param OutputInterface $output
     * @param string $eventsDataJson
     * @return void
     * @throws \JsonException
     */
    private function handleContainerKillEvent(
        OutputInterface $output,
        string $eventsDataJson
    ): void {
        $output->writeln(PHP_EOL . 'Received event data:', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln($eventsDataJson . PHP_EOL, OutputInterface::VERBOSITY_VERBOSE);
        $proxyContainerName = $this->getProxyContainerName();
        $killedContainers = [];

        foreach (explode(PHP_EOL, $eventsDataJson) as $eventDataJson) {
            try {
                $eventData = json_decode($eventDataJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $output->writeln('Failed to decode event data: ' . $eventDataJson . PHP_EOL);
                $output->writeln($e->getMessage() . PHP_EOL);

                continue;
            }

            $containerId = $eventData['Actor']['ID'];
            $containerName = $eventData['Actor']['Attributes']['name'];

            if ($containerName === $proxyContainerName) {
                throw new \RuntimeException('Proxy container was killed');
            }

            if (
                !isset($eventData['Actor']['Attributes']['traefik.enable'])
                || $eventData['Actor']['Attributes']['traefik.enable'] !== 'true'
            ) {
                continue;
            }

            $output->writeln(sprintf('Killed container: %s (%s)', $containerName, $containerId));
            $killedContainers[] = $containerId;
        }

        // Remove all killed containers from the networks
        foreach ($this->networkContainers as $truncatedNetworkId => $containers) {
            $networkContainers = array_keys($containers);
            $killedNetworkContainers = array_intersect($networkContainers, $killedContainers);

            if (!$killedNetworkContainers) {
                continue;
            }

            $output->writeln(sprintf('Network %s contains killed containers:', $truncatedNetworkId));

            foreach ($killedNetworkContainers as $killedNetworkContainer) {
                $output->writeln(sprintf('- %s (%s)', $containers[$killedNetworkContainer], $killedNetworkContainer));
                unset($this->networkContainers[$truncatedNetworkId][$killedNetworkContainer]);
            }

            $output->writeln(sprintf('Network %s contains left:', $truncatedNetworkId));

            foreach ($this->networkContainers[$truncatedNetworkId] as $containerId => $containerName) {
                $output->writeln(sprintf('- %s (%s)', $containerName, $containerId));
            }
        }

        $this->disconnectProxyFromEmptyNetworks($output);
    }

    /**
     * @param OutputInterface $output
     * @return void
     */
    private function connectProxyToNonEmptyNetworks(OutputInterface $output): void
    {
        $proxyContainerId = $this->getProxyContainerId();

        foreach ($this->networkContainers as $truncatedNetworkId => $containers) {
            if (count($containers) && !isset($containers[$proxyContainerId])) {
                $output->writeln('Connecting proxy to the network: ' . $truncatedNetworkId . PHP_EOL);

                try {
                    $this->dockerNetwork->connect($truncatedNetworkId, $proxyContainerId);
                    $this->networkContainers[$truncatedNetworkId][$proxyContainerId] = $this->getProxyContainerName();
                } catch (ProcessFailedException $e) {
                    $output->writeln('Failed to connect proxy to the network: ' . $truncatedNetworkId . PHP_EOL);
                    $output->writeln($e->getMessage() . PHP_EOL);
                }
            }
        }
    }

    /**
     * Disconnect proxy from empty networks (i.e., networks with only proxy container)
     * We don't remove empty networks because we don't know the user intention
     *
     * @param OutputInterface $output
     * @return void
     */
    private function disconnectProxyFromEmptyNetworks(OutputInterface $output): void
    {
        $proxyContainerId = $this->getProxyContainerId();
        $proxyContainerName = $this->getProxyContainerName();

        foreach ($this->networkContainers as $truncatedNetworkId => $containers) {
            // If the only container is proxy, disconnect it from the network
            if (count($containers) === 1 && isset($containers[$proxyContainerId])) {
                $output->writeln(sprintf(
                    'Disconnecting "%s" from the network: %s' . PHP_EOL,
                    $proxyContainerName,
                    $truncatedNetworkId
                ));
                // Failed to disconnect: maybe it's already disconnected?
                unset($this->networkContainers[$truncatedNetworkId][$proxyContainerId]);

                try {
                    $this->dockerNetwork->disconnect($truncatedNetworkId, $proxyContainerId);
                } catch (ProcessFailedException $e) {
                    $output->writeln('Failed to disconnect proxy from the network: ' . $truncatedNetworkId . PHP_EOL);
                    $output->writeln($e->getMessage() . PHP_EOL);
                }
            }
        }
    }

    /**
     * @return string
     */
    private function getProxyContainerName(): string
    {
        return $this->reverseProxyContainerName;
    }

    /**
     * @return string
     */
    private function getProxyContainerId(): string
    {
        return $this->reverseProxyContainerId;
    }

    /**
     * @return string
     */
    private function getReverseProxyTruncatedNetworkId(): string
    {
        return $this->reverseProxyTruncatedNetworkId;
    }
}
