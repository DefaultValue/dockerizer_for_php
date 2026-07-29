# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

## Project Overview

Dockerizer for PHP is a Symfony Console CLI tool that generates and manages
Docker Compose configurations for PHP applications, with deep Magento 2
integration. It automates SSL certificates (`mkcert`), Traefik reverse proxy
configuration, `/etc/hosts` management, and AWS S3 database backups.

Entry point: `bin/dockerizer` — a PHP executable that boots a Symfony DI
container and runs the console application.

## Common Commands

```bash
# Install dependencies
composer install

# Run the CLI
php bin/dockerizer <command>

# Static analysis (level 8)
vendor/bin/phpstan analyse -l 8 ./src/

# Code style check (PSR-12)
vendor/bin/phpcs --standard=PSR12 --severity=1 ./src/

# List all available CLI commands
php bin/dockerizer list
```

There are no unit tests — quality is enforced via phpstan (level 8) and phpcs
(PSR-12). Integration testing uses `magento:test-*` commands against real Docker
environments.

## Architecture

### Bootstrap Flow

`bin/dockerizer` → `Kernel::getApplication()` → loads `.env.dist` / `.env.local`
→ builds Symfony DI container from `config/services.yaml` → registers console
commands via tags → runs `Application::run()`.

Custom `--with-*` CLI options are sorted to the end of argv before Symfony
parses them (see `bin/dockerizer`).

### Namespace → Responsibility Map

- **`Console\Command\`** — CLI commands organized by domain: `Composition/`,
  `Magento/`, `Docker/Mysql/`, `Maintenance/`
- **`Console\CommandOption\`** — Reusable, composable option definitions with
  interactive prompts and validation (`OptionDefinitionInterface`,
  `InteractiveOptionInterface`, `ValidatableOptionInterface`)
- **`Docker\Compose\Composition\`** — Core domain: `Template`, `Service`,
  `DevTools`, `Parameter` — all are DTOs created via `Factory` (non-shared DI
  services)
- **`Docker\Compose\Composition\PostCompilation\`** — Modifier pipeline that
  transforms generated YAML (Traefik, SSL, hosts, mkcert) in sort-order sequence
- **`Docker\ContainerizedService\`** — Wrappers for interacting with running
  containers (Php, Mysql, Elasticsearch, etc.)
- **`Docker\Compose.php`** — Docker Compose CLI wrapper; auto-detects v1
  (`docker-compose`) vs v2 (`docker compose`)
- **`Platform\Magento\`** — Magento-specific workflows: project creation,
  setup:install, version detection
- **`Filesystem\`** — File operations with a `firewall()` check to restrict
  writes to allowed directories
- **`Shell\`** — Process execution wrapper with named timeout tiers (SHORT=60s,
  LONG=3600s, INFINITE=0)
- **`DependencyInjection\Factory`** — Creates fresh instances of
  `DataTransferObjectInterface` services from the DI container

### Template System

Templates live in `templates/vendor/*/*/` with three categories:

- `composition/` — Full composition templates (e.g., Magento with specific
  service stacks)
- `service/` — Individual service definitions (PHP/Apache, MySQL, Redis, etc.)
- `dev_tools/` — Optional development services (PHPMyAdmin, etc.)

Templates are YAML files with `{{parameter_name|modifier1|modifier2}}`
placeholders. The `Parameter` class processes these with a modifier chain:
`first`, `replace:X:Y`, `enclose:'`, `implode:,`, `to_yaml_array:N`, etc.

#### Pin service versions to the requirement that actually governs them

A version is parameterized only when the application dictates it. Otherwise it
is hardcoded in the service template. The two Nginx services are the worked
example:

- `dv_nginx_proxy_for_varnish` (`nginx-proxy`) terminates SSL and proxies to
  Varnish. It runs a hand-written `virtual-host.conf` and never reads Magento's
  config, so Adobe's per-release Nginx requirement does not apply to it. Its tag
  is **hardcoded** in the service template.
- `dv_nginx_magento_for_fpm` (`nginx-magento`) runs Magento's own
  `magento.nginx.conf.sample`. It **must** track the Adobe system requirement
  for the release, so it reads `{{nginx_version}}`.

Hardcode a **pinned** tag, never `latest`. Generated compositions are meant to
be reproducible; a floating tag silently changes what a user gets. Bump pinned
tags periodically, like the PHP images.

#### Where a parameter goes

Global `app.parameters` is the default home: define a value once and let
whichever service the user picks consume it. `mysql_database` and
`rabbitmq_username` live there because they are the same whichever database or
queue option is selected — repeating them per service would be noise.

A per-service `parameters` block is what lets **one service template be offered
as several selectable options** differing by a small value. `php_8_4_fpm` and
`php_8_3_fpm` are both `dv_php_fpm_8_3_to_8_5` and differ only in
`image_version`; the MySQL and MariaDB options work the same way.

`nginx_version` sits on `nginx_web` for a narrower reason: it is the only Nginx
service that has a version at all, so putting it up top would read as if it also
governed `nginx_proxy`.

If two services want different values under one parameter name, that is a naming
problem — give them two named parameters — not a reason to move the value
per-service.

### Composition Build Pipeline

1. User selects a composition template
2. Template organizes services into required/optional groups
3. User selects services per group; missing parameters prompt via
   `UniversalReusableOption`
4. `Composition` (stateful singleton) assembles services: `setTemplate()` →
   `addService()` (multiple) → `dump()`
5. `PostCompilation\ModifierCollection` applies modifiers in sort-order (Traefik
   SSL, mkcert certs, /etc/hosts, etc.)
6. Output: `docker-compose.yaml`, `docker-compose-dev-tools.yaml`, mounted
   config files, `Readme.md`

### Key DI Patterns

- `DataTransferObjectInterface` classes are `shared: false` / `public: true` —
  always created fresh via `Factory->get()`
- Commands auto-registered via `console.command` tag
- Post-compilation modifiers auto-collected via
  `docker.compose.postCompilationModifier` tag
- `AbstractParameterAwareCommand` receives all `OptionDefinitionInterface`
  implementations via tagged iterator

### Environment Variables

Defined in `.env.dist`, overridden by `.env.local` (gitignored):

- `DOCKERIZER_PROJECTS_ROOT_DIR` — root directory for generated projects
- `DOCKERIZER_SSL_CERTIFICATES_DIR` — SSL certificate storage path
- `DOCKERIZER_TRAEFIK_SSL_CONFIGURATION_FILE` — path to Traefik
  certificates.toml

## Workflow

- After significant changes (new features, breaking changes, removals), update
  `Changelog.md` following the
  [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.
- Pre-commit hooks (Husky + lint-staged) auto-run phpstan, phpcs on PHP files
  and Prettier + markdownlint on Markdown files. Requires Node (see `.nvmrc`).

## Dependency Version Management

Pin all dependency versions to a specific minor using the tilde (`~`) operator:

- **Composer (PHP):** Use `~X.Y.0` format — e.g. `~7.4.0` means
  `>=7.4.0, <7.5.0`. This allows patch updates only within the minor version.
- **npm (JS):** Use `~X.Y.0` format — same semantics as Composer.
- **Exception:** `roave/security-advisories` stays at `dev-latest`.

When adding a new dependency, pin it to the currently available minor version.
When upgrading, update the constraint to the new minor and run
`composer update <package>` or `npm update <package>`, then commit the updated
lock file.

Do **not** use wide ranges like `^6.0|^7.0` or `^3` — this project is a
standalone CLI app, not a library, so broad compatibility ranges are unnecessary
and untested.

## Code Conventions

- All PHP files use `declare(strict_types=1)`
- PSR-4 autoloading: `DefaultValue\Dockerizer\` → `src/`
- PSR-12 code style
- PHPStan level 8 strict analysis
- Requires PHP >=8.2
