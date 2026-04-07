---
name: add-service-for-magento
description:
    Guide for adding new Docker services (e.g., cache, message broker, search
    engine) to the Magento 2 Dockerizer
---

# Adding a New Docker Service for Magento 2

## Overview

Adding a new Docker service to the Dockerizer requires changes across multiple
layers. This skill documents the full process using Valkey (cache) and ActiveMQ
Artemis (message broker) as reference implementations.

## Layers to Modify

### 1. Service Template

**Location:**
`templates/vendor/defaultvalue/dockerizer-templates/service/<service_name>/dv_<service_name>.yaml`

Create a Docker Compose YAML fragment. The filename (without `.yaml`) becomes
the **service code** referenced in composition templates.

**Minimal service** (like Redis/Valkey -- no UI, no auth):

```yaml
services:
    <service-name>:
        image: <image>:{{<version_param>}}
        restart: always
```

**Full-featured service** (like RabbitMQ/Artemis -- UI via Traefik, auth,
persistent data, healthcheck):

```yaml
services:
    <service-name>:
        image: <image>:{{<version_param>}}
        restart: always
        labels:
            - traefik.enable=true
            - traefik.http.routers.<name>-{{environment}}-{{domains|first|replace:.:-}}-http.rule=Host(`<name>-{{environment}}-{{domains|first}}`)
            - traefik.http.routers.<name>-{{environment}}-{{domains|first|replace:.:-}}-http.entrypoints=http
            - traefik.http.services.<name>-{{domains|first|replace:.:-}}-http.loadbalancer.server.port=<ui_port>
        environment:
            <ENV_USER>: "{{<user_param>}}"
            <ENV_PASS>: "{{<pass_param>}}"
        volumes:
            - <name>_{{environment}}_data:<data_path>
        healthcheck:
            test: ["CMD", "curl", "-f", "http://localhost:<port>/<path>"]
            interval: 30s
            timeout: 10s
            retries: 5

volumes:
    <name>_{{environment}}_data:
        external: false
```

**Rules:**

- Template parameters use `{{param_name}}` syntax with optional modifiers:
  `{{param|first|replace:.:-}}`
- The service name in the YAML (`services: <service-name>:`) becomes the Docker
  Compose service name
- This name MUST match the constant defined in `AppContainers.php`
- Templates are auto-discovered from `./templates/vendor/*/*/service/`
  (configured in `config/services.yaml`)

### 2. ContainerizedService Class

**Location:** `src/Docker/ContainerizedService/<ServiceName>.php`

Extend `AbstractService`. Override `initialize()` to add a health check with
retry loop. Follow the Opensearch/Elasticsearch pattern.

**When to create a dedicated class:**

- Service needs a health check beyond "is container running" (e.g., checking an
  HTTP endpoint or running a CLI command)
- Service exposes metadata that other code needs (e.g., Elasticsearch's
  `getMeta()`)

**When to use `Generic`:**

- Service just needs basic container operations (`getEnvironmentVariable()`,
  `run()`, `mustRun()`)
- No special health check needed (e.g., Redis, RabbitMQ, Varnish)

**Health check pattern:**

```php
class ServiceName extends AbstractService
{
    private const CONNECTION_RETRIES = 60;
    private const STATE_CONNECTION_RETRIES = 10;

    public function initialize(string $containerName): static
    {
        $self = parent::initialize($containerName);
        $self->testConnection();
        return $self;
    }

    private function testConnection(int $connectionRetries = self::CONNECTION_RETRIES): void
    {
        $stateConnectionRetries = min($connectionRetries, self::STATE_CONNECTION_RETRIES);

        while ($connectionRetries--) {
            try {
                if ($this->getState() !== Container::CONTAINER_STATE_RUNNING) {
                    --$stateConnectionRetries;
                }
                if (!$stateConnectionRetries) {
                    throw new ContainerStateException(
                        '', 0, null, $this->getContainerName(), Container::CONTAINER_STATE_RUNNING
                    );
                }

                // YOUR HEALTH CHECK HERE (e.g., curl, CLI ping, etc.)
                $this->mustRun('health-check-command', Shell::EXECUTION_TIMEOUT_SHORT, false);
                return;
            } catch (ProcessFailedException) {
                if ($connectionRetries) {
                    sleep(1);
                    continue;
                }
                throw new \RuntimeException(
                    sprintf('Container "%s" is not responding', $this->getContainerName())
                );
            }
        }
    }
}
```

### 3. AppContainers Constant

**File:** `src/Platform/Magento/AppContainers.php`

Add a `public const` with the Docker Compose service name (must match the
`services:` key in the template YAML):

```php
public const MY_SERVICE = 'my-service-name';
```

### 4. Magento.php Initialization

**File:** `src/Platform/Magento.php`

1. **Inject the service** in the constructor (dedicated class or Generic)
2. **Add initialization** in `initialize()` method -- check
   `$dockerCompose->hasService()` and call `->initialize()`
3. If the new service replaces an existing one (e.g., Valkey replaces Redis),
   use `elseif` to make them mutually exclusive. Check the newer/preferred
   service first.

### 5. SetupInstall.php Configuration

**File:** `src/Platform/Magento/SetupInstall.php`

In `updateMagentoConfig()`, add the Magento CLI configuration commands. Use
`$appContainers->hasService()` to check availability and
`$appContainers->getService()->getEnvironmentVariable()` to read credentials.

Common Magento config patterns:

- **Cache:**
  `setup:config:set --cache-backend=redis --cache-backend-redis-server=<host> ...`
- **Session:**
  `setup:config:set --session-save=redis --session-save-redis-host=<host> ...`
- **AMQP:**
  `setup:config:set --amqp-host=<host> --amqp-port=5672 --amqp-user=<user> --amqp-password=<pass>`
- **Search:** Handled in the install command itself, not in
  `updateMagentoConfig()`

If the new service replaces an existing one, use `elseif` and check the newer
service first (consistent with `Magento.php::initialize()`).

### 6. Composition Templates

Add the new service to relevant Magento version composition templates in
`templates/vendor/defaultvalue/dockerizer-templates/composition/magento/<version>/`.

Place in `optional:` section if the service is not required for Magento to
function (Redis, Valkey, RabbitMQ, Artemis are all optional).

If the new service replaces an existing one, they go in the same group (e.g.,
`cache:` group with both Redis and Valkey options, `message_queue:` group with
both RabbitMQ and Artemis options).

## Reference Implementations

- **Minimal service (Valkey):** `service/valkey/dv_valkey.yaml`,
  `ContainerizedService/Valkey.php`
- **Full-featured service (ActiveMQ Artemis):**
  `service/activemq_artemis/dv_activemq_artemis.yaml`,
  `ContainerizedService/ActivemqArtemis.php`
- **Generic service (Redis):** `service/redis/dv_redis.yaml`, initialized via
  `Generic` in `Magento.php`

## Verification

1. `vendor/bin/phpstan analyse -l 8 ./src/` -- all new/modified files pass level
   8
2. `vendor/bin/phpcs --standard=PSR12 --severity=1 ./src/` -- PSR-12 compliance
3. `php bin/dockerizer list` -- CLI boots without errors
4. Template discovery works (service code appears in composition options)
