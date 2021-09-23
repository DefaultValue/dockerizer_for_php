# Changelog

All notable changes to this project will be documented in this file since v2.0.0

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


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