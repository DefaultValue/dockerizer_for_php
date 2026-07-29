# Changelog

All notable changes to this project will be documented in this file since v2.0.0

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.5.1] - 2026-08-31

### Added

- Magento **2.4.6-p15**, **2.4.7-p10**, and **2.4.8-p5** composition templates.
- Magento **2.4.9** GA templates (`_p0`), replacing the `2.4.9-beta1` drafts.
- `nginx_version` parameter on the `nginx_web` service, pinned to each release's
  Adobe requirement: 2.4.8 and -p1 → `1.26`, -p2 to -p4 → `1.28`, -p5 and 2.4.9
  → `1.30`.
- Separate **2.4.8-p1** and **2.4.8-p2** PHP-FPM templates, split from the
  former `_p1_p2` template — Adobe raises the Nginx requirement from 1.26 to
  1.28 at -p2.

### Changed

- Varnish upgraded to **8.0** for 2.4.6-p15, 2.4.7-p10, 2.4.8-p5, and 2.4.9,
  reusing the existing `varnish7.vcl` configuration.
- Ported the upstream `varnish7.vcl` tracking-parameter regex update
  (`gad_source` → `gad_[a-z]+`) into `varnish_magento_v7.vcl`.
- Per-patch supported-service changes:
    - **2.4.6-p15**: removed Elasticsearch, MySQL, and Redis; added OpenSearch
      3; Valkey 8 → 8.1.
    - **2.4.7-p10**: removed MySQL and Redis; Elasticsearch 7.17 → 8.17; added
      OpenSearch 3 and MariaDB 11.8; Valkey 8 → 8.1; RabbitMQ 4.1 → 4.2.
    - **2.4.8-p5**: added MariaDB 11.8; Valkey 8 → 8.1; RabbitMQ 4.1 → 4.2.
    - **2.4.9**: PHP 8.5, MariaDB 12.3, MySQL 8.4, OpenSearch 3.7.0, Valkey 9,
      RabbitMQ 4.2 (no Elasticsearch, no Redis).
- Nginx SSL-termination proxy (`dv_nginx_proxy_for_varnish`) hardcodes
  `nginx:1.30` instead of reading `nginx_version` — it proxies to Varnish and
  never reads Magento's config, so the per-release requirement does not apply to
  it. `nginx_version` is gone from every composition's global `app.parameters`
  and now governs only `nginx_web`, which runs `magento.nginx.conf.sample`.
  Previously one global fed both services, so the PHP-FPM stack could not give
  them different versions.
- **Breaking:** the SSL-termination proxy is now `ssl_termination_proxy` >
  `nginx_proxy` in every composition, replacing four inconsistent spellings
  (groups `nginx` / `nginx_ssl_proxy`, codes `nginx` / `nginx_latest`). The
  group names the role, the code names the implementation. Saved
  `--required-services` values naming an old code must be updated.
- Composer and npm dependencies updated, including the PHP_CodeSniffer 3 → 4 and
  Guzzle 7 → 8 major bumps.

### Removed

- Unused `Docker\Network` dependency from `Docker\Compose`.

### Fixed

- Test harness (`magento:test-templates` and other multithread tests) now logs
  the actual cause of a failed run — the command, why it failed, and its output
  — instead of a bare `FAILED!` line.
- `magento:test-templates` now retries `deploy:mode:set developer`, which
  intermittently fails on the macOS Docker bind mount with
  `rmdir(...): Directory not empty`.
- `setup:install` now derives the `--search-engine=elasticsearch7|8` flag from
  the running Elasticsearch container instead of the Magento version, which
  picked the wrong engine for releases supporting both (e.g. 2.4.7-p10).

## [3.5.0] - 2026-05-13

### Added

- Magento 2.4.8+ `Nginx + Varnish + Nginx + PHP-FPM` web stack.
- `php_fpm_development_image` dev-tools service for the FPM stack.
- `php_apache_development_image_8_3` dev-tools service — variant of
  `php_apache_development_image` that tracks the new PHP 8.3+ image tag scheme.

### Changed

- UX: show recommended templates between the choice list and the input prompt.
- Docker image tagging policy for PHP 8.3+: tags now include the web server
  variant — `{version}-{apache|fpm}-{production|development}` (e.g.
  `defaultvalue/php:8.4.19-apache-production`,
  `defaultvalue/php:8.4.19-fpm-development`). PHP ≤ 8.2 images keep the previous
  `{version}-{stage}` scheme.
- Apache service templates `dv_php_apache_8_3.yaml` and
  `dv_php_apache_unsecure_8_3.yaml` reference
  `{{image_version}}-apache-production`.
- Compositions using PHP 8.3+ Apache services (Magento 2.4.7, 2.4.8, 2.4.9)
  reference `php_apache_development_image_8_3`.

### Fixed

- Generated composition `Readme.md` now shows the `/console` path for the Apache
  ActiveMQ Artemis dashboard URL.
- RabbitMQ service template (`dv_rabbitmq.yaml`) volume mount target corrected
  from `/var/lib/mysql` to `/var/lib/rabbitmq`.

## [3.4.2] - 2026-04-08

### Added

- Magento templates for 2.4.8 and 2.4.9-beta1.
- PHP 8.4 and 8.5 support.
- MariaDB 11 support.

## [3.4.1] - 2026-04-08

### Added

- Magento templates from 2.4.4 to 2.4.7-p9.
- Valkey service template (`dv_valkey`): Redis-compatible cache for Magento 2.
- Automatic Valkey/Redis cache and session configuration during Magento setup.
- ActiveMQ Artemis service template (`dv_activemq_artemis`): AMQP message broker
  for Magento 2.
- Automatic ActiveMQ Artemis configuration via STOMP protocol during Magento
  setup.
- Post-setup validation of `app/etc/env.php` to ensure all running services
  (Valkey/Redis cache+session, Artemis STOMP, RabbitMQ AMQP, Varnish HTTP cache)
  are actually configured. Fails loudly if any expected config is missing.
- `docker compose up --wait` blocks until all services with healthchecks are
  healthy, catching misconfigured containers immediately.
- Healthchecks added to MySQL/MariaDB, Redis, Valkey, OpenSearch, Elasticsearch,
  RabbitMQ, and phpMyAdmin service templates.
- Mailhog URL check added to `magento:test-templates` dev tools validation.

## [3.4.0] - 2026-04-06

### Added

- Pre-commit hooks via `Husky` and `lint-staged` (Node 24) to validate PHP code
  (`phpstan`, `phpcs`) and auto-format Markdown files (`Prettier`,
  `markdownlint-cli2`).

### Changed

- Supported PHP version range updated to `>=8.2.0 && <=8.5.0`.
- All related PHP packages updated to the latest possible versions.
- Performance: `magento:test-templates` does not sync files with host OS on
  macOS.

### Fixed

- Fixed issue with parsing Docker events data in the
  `maintenance:traefik:update-networks` command.

### Removed

- Docker Compose v1 (`docker-compose`) support. Only Docker Compose v2
  (`docker compose`) is supported now.
- Drop support for Magento 2.0.x, 2.1.x, 2.2.x, 2.3.x, 2.4.0, 2.4.1 These
  versions are not installable anymore.
- Drop `magento:test-dockerfiles` comamnd.
- Drop `bitnami/mariadb` images support.

## [3.2.2] - 2024-04-12

### Added

- Magento 2.4.7, 2.4.6-p5, 2.4.5-p7, 2.4.4-p8 templates

### Changed

- Adjustments to the Magento 2.4.4+ templates according to the system
  requirements

## [3.3.1] - 2024-02-28

### Added

- Magento `2.4.7-beta3` templates.

### Changed

- Automatically remove root `version` tag from the `docker-compose*.yml` files
  for Docker compose V2.

## [3.3.0] - 2024-01-07

### Added

- PHP 8.3 support.
- MacOS and Docker Desktop support. PHP images in the DV compositions also now
  support the `linux/arm64/v8` architecture.
- New command `maintenance:traefik:update-networks` to help Docker Desktop users
  to watch for the network changes and add/remove Traefik to/from the networks
  automatically in case it's not possible to use `network_mode: host`.
- Added `$application->setCatchExceptions(false);` to `bin/dockerizer` to allow
  propagating exceptions to the console.
- OpenSearch Dashboards as a dev tool for OpenSearch.

### Changed

- Improved PHP version constraints.
- Use `docker exec` instead of PHP PDO to connect to MySQL (MacOS
  compatibility).
- Use `docker exec` and `docker cp` and Linux commands to work with files inside
  a container instead of PHP functions in the host OS.
- Better support for MacOS filesystem: '/private/etc/hosts' and correctly get
  system temp directory.
- Pull Docker images before starting a composition.

## [3.2.1] - 2023-06-27

### Added

- Magento `2.4.7-beta1` and `2.4.6-p1` templates.
- OpenSearch `2.5` for Magento `2.4.6` and later.

### Changed

- Bump Elasticsearch version from `7.17.5` to `7.17.10` for Magento `2.4.5-p2` -
  `2.4.6`.
- Stick to MySQL `8.0.28` for Magento `2.4.1` - `2.4.5` as stated in the system
  requirements.

### Fixed

- Downgraded Traefik template to use docker-compose file format v3.7. Later
  formats are not supported by Docker shipped with Ubuntu 20.04.

## [3.2.0] - 2023-06-15

### Added

- [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki)
- Added new commands: `docker:mysql:connect`, `docker:mysql:export-db`,
  `docker:mysql:import-db`, `docker:mysql:upload-to-aws`,
  `docker:mysql:generate-metadata`, `docker:mysql:reconstruct-db`,
  `docker:mysql:test-metadata`, `maintenance:traefik:cleanup-certificates`.
- Nginx/Apache containers now have network aliases instead of `extra_hosts` in
  the `docker-compose.yml` files.
- Generating random passwords for MySQL with ability to pass the password via
  the command options.

### Changed

- Better auto-generated `Readme.md` for the commands.

### Fixed

- Various improvements to generating Docker compositions and installing Magento.
- Various issues with multithreading, cleanup, and other minor issues.

### Removed

- Deleted `extra_hosts` from the `docker-compose.yml` files.
- Removed hardcoded `root` user password from the MySQL containers.

## [3.1.3] - 2022-09-26

### Added

- Output final service parameters and mounted files after dumping a composition.

### Fixed

- Allow empty optional services list.
- Allow passing empty option value via universal options like
  `--with-web-root=""`.
- Make upgrade.sh more compatible with the latest Git versions.
- Setting non-interactive mode for the `magento:setup` command.

## [3.1.2] - 2022-09-08

### Added

- Added `restart: always` to the MailHog container.

## [3.1.1] - 2022-09-08

### Added

- Added Shopware 5 and 6 templates.

## [3.1.0] - 2022-09-05

### Added

- Added and tested Magento 2.4.5 templates.
- Slightly better error handling for `composer create-project`.
- Added more tests to the `magento:test-dockerfiles` command to cover more
  issues.
- Implemented simple check for xDebug configuration in
  `magento:test-dockerfiles`.

### Changed

- Implemented service-level dev tools instead of the global dev tools.
- Moved all templates inside the directory
  `templates/vendor/defaultvalue/dockerizer-templates` to emulate moving them to
  a separate repository.

### Removed

- Removed non-persistent version of Elasticsearch service from all templates.
  The need to refresh data after every restart is not really convenient.

## [3.0.4] - 2022-08-10

### Added

- Added `path` argument to `composition:get-container-name` and
  `composition:get-container-ip` commands for easier usage with CI/CD.

## [3.0.3] - 2022-07-22

### Added

- Use `monolog/monolog` for logging in the console commands.
- New command to test Dockerfiles with Magento: `magento:test-dockerfiles`. This
  is for internal testing only.

## [3.0.2] - 2022-07-11

### Added

- New command moved from v2: `magento:test-module-install`. Multiple folders
  with module(s) can be passed at once.

## [3.0.1] - 2022-07-07

### Added

- Security: implemented basic protection from working outside the system temp
  directory or `PROJECTS_ROOT_DIR`. Less chance to delete something really
  important in you OS.
- Added template for generic PHP application template.

### Changed

- Nginx virtual host not overwrites the default file in
  `/etc/nginx/conf.d/default.conf`.

## [3.0.0] - 2022-06-09

Dockerizer v3.0.0 released!
[Please, check the presentation for more information](https://docs.google.com/presentation/d/1jLC1yaabB9bFh_4nnQZYGwHmVe8Vit6OgAsBjIjEKog/edit?usp=sharing)
[Video](https://www.youtube.com/watch?v=88fCLnOnLvA)

## [2.4.0] - 2021-09-23

### Added

- New option for `--mount-root` for `dockerize` and `env:add` commands. This
  option sets mount directory for projects were Docker files are not located in
  the same directory as Docker configurations.
- New option for `--web-root` for `env:add` command. This option allows setting
  web root other than `pub/`.
- Added new question classes: `ProjectMountRoot` and `WebRoot`.

### Changed

- Changed option name from `webroot` to `web-root` for better readability and
  consistence with other option names.

### Fixed

- Fixed minor issue when web root was set to `/var/www/html//` (with double `/`
  at the end).

### Deprecated

- Deprecated `env:add` command in favour of consolidating it with the
  `dockerize` command in the future.

## [2.3.0] - 2021-07-23

### Added

- `dockerize` - ask for confirmation if project root is outside the directory
  defined in the `PROJECTS_ROOT_DIR` environment variable.
- `\App\Services\SslCErtificate` - new class responsible for generating SSL
  certificates via `mkcert`.
- show full `dockerize` command after entering all parameters for reference and
  for the future re-use if needed.

### Changed

- Updated dependencies.

### Fixed

- Project cleanup works properly and no files owned by root are left.

## [2.2.3] - 2021-04-26

### Added

- Support `Composer v2` by adding `--composer-version` option to the following
  commands: `dockerize`, `env:add`.
- Display exception from MySQL for easier debug in case the connection can't be
  established.

### Changed

- Generating individual file names per environment for SSL certificates.

### Fixed

- Compatibility issue with MySQL 8.0 (deprecated way to create used and grant
  permissions).
- Fixed `test:hardware` command to work properly with Magento 2.4.x (previously
  Magento was linked to the wrong MySQL container).
- Proper creating user in MySQL from PHP 7.3 (for example, for Magento 2.4.0
  with PHP 7.3).

## [2.2.2] - 2020-10-16

### Added

- Command `env:add` now generated per-environment virtual host files and
  separate SSL certificate files. Previously everything was placed in one file.
- Default `.gitignore` for Magento 2.4.1 (based on 2.4.0) when installing
  Magento.

### Changed

- Added empty line to the end of the Magneto 2.4.1 `.gitignore` (`magento:setup`
  added custom ignores in the wrong way).
- Using individual virtual host file per environment.

## [2.2.1] - 2020-10-16

### Added

- Hotfix for Magento 2.4.1: added default `.gitignore` file for Magento 2.4.1
  (taken from 2.4.0) because it is missed from the 2.4.1 release.

## [2.2.0] - 2020-10-05

### Changed

- Updated dependencies and locked minor version for better stability.
- Parameter name and usage from `$elasticsearchVersion` to `$elasticsearchHost`
  in the Magneto-related commands.

### Removed

- Removed option `--mysql-container` from the command
  `magento:test-module-install` to dynamically find current linked MySQL
  container.

## [2.1.0] - 2020-08-04

### Added

- New `test:dockerfiles` command to test running different Magento versions
  before publishing the Dockerfiles.
- Implemented `--execution-environment` (`-e`) option for `magento:setup` and
  `dockerize` commands. Must be used only for testing! Use prebuild images for
  yor projects;
- Implemented `--elasticsearch` option for `dockerize` and `env:add` commands.
  Automatically added when setting up Magento 2.4.0

### Changed

- Renamed command from `hardware:test` to `test:hardware` and mover the class to
  `App\Command\Test` namespace.
- Extracted all common functionality for the `test:hardware` command into an
  abstract class (compatibility-breaking change).
- Moved all logs to the same location - `var/log/`.
- Git user name and email, Magento admin user name and email changed to the
  neutral ones.

## [2.0.0] - 2020-05-21

### Added

- DI container `php-di/php-di` (replaces `symfony/dependency-injection`).
- New `env:add` command for creating multiple environments
  (staging/test/dev/etc. in addition to production).
- New `hardware:test` command for easy hardware performance tests and
  infrastructure build testing.
- Ability to choose SQL DB version during installation.
- Introduced the `\App\CommandQuestion\QuestionInterface`. Question classes
  automatically add options/argument to the command that uses them. This makes
  command classes smaller and reduces code duplication.
- Introduced the `\App\CommandQuestion\Pool` to reduce Command class
  constructors.
- Introduced a few new services to extract common or unnatural code from the
  commands.

### Changed

- Only default file `docker-compose.yml` is used by default.
- Command `magento:setup` renamed to `magento:setup`
- Better default parameters handling.

### Removed

- Symfony components `symfony/framework-bundle` and
  `symfony/dependency-injection`.
- Replaced `--prod` and `--dev` options with `--domains` option from the
  `\App\CommandQuestion\Question\Domains` class.
