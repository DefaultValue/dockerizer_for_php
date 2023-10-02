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
class UpdateNetworks extends \DefaultValue\Dockerizer\Console\Command\AbstractParameterAwareCommand
{
    protected static $defaultName = 'maintenance:traefik:update-networks';

    protected array $commandSpecificOptions = [
        CommandOptionDockerContainer::OPTION_NAME
    ];

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
            ->setHelp(<<<'EOF'

            EOF)
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
            // Handle container creation and connecting container to the network
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($proxyContainerName, $output) {
                    $this->connectToNetworks($proxyContainerName, $output, $eventsDataJson);
                },
                [
//                    ['type=' . Events::EVENT_TYPE_CONTAINER, 'event=create'],
                    ['type=' . Events::EVENT_TYPE_NETWORK, 'event=connect']
                ]
            );

            // Handle container kill and disconnecting container from the network
            $this->dockerEvents->addHandler(
                function (string $eventType, string $eventsDataJson) use ($proxyContainerName, $output) {
                    $this->disconnectFromEmptyNetworks($proxyContainerName, $output, $eventsDataJson);
                },
                [
//                    ['type=' . Events::EVENT_TYPE_CONTAINER, 'event=kill'],
                    ['type=' . Events::EVENT_TYPE_NETWORK, 'event=disconnect']
                ]
            );
            $this->dockerEvents->watch();
        }

        return self::SUCCESS;
    }

    /**
     * Disconnect proxy from empty networks (i.e., networks with only proxy container)
     * And remove empty networks
     * NOTE! For now, we don't check whether any container have `traefik.enable=true` label
     *
     * @param string $proxyContainerName
     * @param OutputInterface $output
     * @param string $eventsDataJson
     * @return void
     * @throws \JsonException
     */
    private function disconnectFromEmptyNetworks(
        string $proxyContainerName,
        OutputInterface $output,
        string $eventsDataJson = ''
    ): void {
        if (!($proxyContainerId = $this->getProxyContainerId($proxyContainerName, $output))) {
            return;
        }

        foreach ($this->getNetworks($proxyContainerId, $eventsDataJson) as $network) {
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
                try {
                    $output->writeln('Removing proxy from network: ' . $network);
                    $this->dockerNetwork->disconnect($network, $proxyContainerName);
                } catch (ProcessFailedException $e) {
                    $output->writeln("<error>{$e->getMessage()}</error>");
                }

                try {
                    $output->writeln('Removing network: ' . $network);
                    $this->dockerNetwork->rm($network);
                } catch (ProcessFailedException $e) {
                    $output->writeln("<error>{$e->getMessage()}</error>");
                }
            }
        }
    }

    /**
     *
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

        foreach ($this->getNetworks($proxyContainerId, $eventsDataJson) as $network) {
            if (str_contains($network, $proxyContainerName)) {
                continue;
            }

            $networkContainers = $this->dockerNetwork->inspectJsonWithDecode($network, '{{json .Containers}}');

            // @TODO: Check the `traefik.docker.network` label and connect traefik only to this network
            if (!isset($networkContainers[$proxyContainerId])) {
                $output->writeln('Connecting proxy to network: ' . $network);
                $this->dockerNetwork->connect($network, $proxyContainerName);
            }
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
     * @param string $proxyContainerId
     * @param string $eventsDataJson
     * @return string[]
     * @throws \JsonException
     */
    private function getNetworks(string $proxyContainerId, string $eventsDataJson): array
    {
        if ($eventsDataJson) {
            $networks = [];
            // Not sure why this happens, but we need to ignore bridge network
            $bridgeNetworkId = $this->dockerNetwork->inspect('bridge', '{{index .Id}}');

            foreach (explode(PHP_EOL, trim($eventsDataJson)) as $eventDataJson) {
                $eventData = json_decode($eventDataJson, true, 512, JSON_THROW_ON_ERROR);

                // Ignore removing proxy itself from the network
                if ($eventData['Actor']['Attributes']['container'] === $proxyContainerId) {
                    continue;
                }

                $networks[] = $eventData['Actor']['ID'];
            }

            $networks = array_diff(array_unique($networks), [$bridgeNetworkId]);
        } else {
            $networks = $this->dockerNetwork->ls();
        }

        return array_diff($networks, ['bridge', 'host', 'none']);
    }
}
