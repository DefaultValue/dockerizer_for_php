# Changelog

All notable changes to this project will be documented in this file since v2.0.0

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.4] - 2022-??-??

### Changed

- Removed `<info>` tag from the command short descriptions.

### Fixed

- Fixed command description for `` and `composition:get-container-ip` commands.


## [3.1.3] - 2022-09-26

### Added

- Output final service parameters and mounted files after dumping a composition

### Fixed

- Allow empty optional services list.
- Allow passing empty option value via universal options like `--with-web-root=""`.
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
- Added more tests to the `magento:test-dockerfiles` command to cover more issues.
- Implemented simple check for xDebug configuration in `magento:test-dockerfiles`.

### Changed

- Implemented service-level dev tools instead of the global dev tools.
- Moved all templates inside the directory `templates/vendor/defaultvalue/dockerizer-templates` to emulate moving them to a separate repository.

### Removed

- Removed non-persistent version of Elasticsearch service from all templates. The need to refresh data after every restart is not really convenient.


## [3.0.4] - 2022-08-10

### Added

- Added `path` argument to `composition:get-container-name` and `composition:get-container-ip` commands for easier usage with CI/CD.


## [3.0.3] - 2022-07-22

### Added

- Use `monolog/monolog` for logging in the console commands.
- New command to test Dockerfiles with Magento: `magento:test-dockerfiles`. This is for internal testing only.


## [3.0.2] - 2022-07-11

### Added

- New command moved from v2: `magento:test-module-install`. Multiple folders with module(s) can be passed at once.


## [3.0.1] - 2022-07-07

### Added

- Security: implemented basic protection from working outside the system temp directory or `PROJECTS_ROOT_DIR`. Less chance to delete something really important in you OS.
- Added template for generic PHP application template.

### Changed

- Nginx virtual host not overwrites the default file in `/etc/nginx/conf.d/default.conf`.


## [3.0.0] - 2022-06-09

Dockerizer v3.0.0 released! [Please, check the presentation for more information](https://docs.google.com/presentation/d/1jLC1yaabB9bFh_4nnQZYGwHmVe8Vit6OgAsBjIjEKog/edit?usp=sharing)
[Video](https://www.youtube.com/watch?v=88fCLnOnLvA)

## [2.4.0] - 2021-09-23

### Added

- New option for `--mount-root` for `dockerize` and `env:add` commands. This option sets mount directory for projects were Docker files are not located in the same directory as Docker configurations.
- New option for `--web-root` for `env:add` command. This option allows setting web root other than `pub/`.
- Added new question classes: `ProjectMountRoot` and `WebRoot`.

### Changed

- Changed option name from `webroot` to `web-root` for better readability and consistence with other option names.

### Fixed

- Fixed minor issue when web root was set to `/var/www/html//` (with double `/` at the end).

### Deprecated

- Deprecated `env:add` command in favour of consolidating it with the `dockerize` command in the future.


## [2.3.0] - 2021-07-23

### Added

- `dockerize` - ask for confirmation if project root is outside the directory defined in the `PROJECTS_ROOT_DIR` environment variable.
- `\App\Services\SslCErtificate` - new class responsible for generating SSL certificates via `mkcert`.
- show full `dockerize` command after entering all parameters for reference and for the future re-use if needed.

### Changed

- Updated dependencies.

### Fixed

- Project cleanup works properly and no files owned by root are left.


## [2.2.3] - 2021-04-26

### Added

- Support `Composer v2` by adding `--composer-version` option to the following commands: `dockerize`, `env:add`.
- Display exception from MySQL for easier debug in case the connection can't be established.

### Changed

- Generating individual file names per environment for SSL certificates.

### Fixed

- Compatibility issue with MySQL 8.0 (deprecated way to create used and grant permissions).
- Fixed `test:hardware` command to work properly with Magento 2.4.x (previously Magento was linked to the wrong MySQL container).
- Proper creating user in MySQL from PHP 7.3 (for example, for Magento 2.4.0 with PHP 7.3).


## [2.2.2] - 2020-10-16

### Added

- Command `env:add` now generated per-environment virtual host files and separate SSL certificate files. Previously everything was placed in one file.
- Default `.gitignore` for Magento 2.4.1 (based on 2.4.0) when installing Magento.

### Changed

- Added empty line to the end of the Magneto 2.4.1 `.gitignore` (`magento:setup` added custom ignores in the wrong way).
- Using individual virtual host file per environment.


## [2.2.1] - 2020-10-16

### Added

- Hotfix for Magento 2.4.1: added default `.gitignore` file for Magento 2.4.1 (taken from 2.4.0) because it is missed from the 2.4.1 release.


## [2.2.0] - 2020-10-05

### Changed

- Updated dependencies and locked minor version for better stability.
- Parameter name and usage from `$elasticsearchVersion` to `$elasticsearchHost` in the Magneto-related commands.

### Removed

- Removed option `--mysql-container` from the command `magento:test-module-install` to dynamically find current linked MySQL container.


## [2.1.0] - 2020-08-04

### Added

- New `test:dockerfiles` command to test running different Magento versions before publishing the Dockerfiles.
- Implemented `--execution-environment` (`-e`) option for `magento:setup` and `dockerize` commands. Must be used only for testing! Use prebuild images for yor projects;
- Implemented `--elasticsearch` option for `dockerize` and `env:add` commands. Automatically added when setting up Magento 2.4.0

### Changed

- Renamed command from `hardware:test` to `test:hardware` and mover the class to `App\Command\Test` namespace.
- Extracted all common functionality for the `test:hardware` command into an abstract class (compatibility-breaking change).
- Moved all logs to the same location - `var/log/`.
- Git user name and email, Magento admin user name and email changed to the neutral ones.


## [2.0.0] - 2020-05-21

### Added

- DI container `php-di/php-di` (replaces `symfony/dependency-injection`).
- New `env:add` command for creating multiple environments (staging/test/dev/etc. in addition to production).
- New `hardware:test` command for easy hardware performance tests and infrastructure build testing.
- Ability to choose SQL DB version during installation.
- Introduced the `\App\CommandQuestion\QuestionInterface`. Question classes automatically add options/argument
to the command that uses them. This makes command classes smaller and reduces code duplication.
- Introduced the `\App\CommandQuestion\Pool` to reduce Command class constructors.
- Introduced a few new services to extract common or unnatural code from the commands.

### Changed

- Only default file `docker-compose.yml` is used by default.
- Command `magento:setup` renamed to `magento:setup`
- Better default parameters handling.

### Removed

- Symfony components `symfony/framework-bundle` and `symfony/dependency-injection`.
- Replaced `--prod` and `--dev` options with `--domains` option from the `\App\CommandQuestion\Question\Domains` class.