# Changelog

All notable changes to this project will be documented in this file since v2.0.0

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [2.2.3] - 2020-10-25

### Added

- Default `.gitignore` for Magento 2.4.1 (based on 2.4.0) when installing Magento.

### Changed

- Using individual virtual host file per environment.

### Fixed

- Compatibility issue with MySQL 8.0 (deprecated way to create used and grant permissions).


## [2.2.2] - 2020-10-16

### Added

- Command `env:add` now generated per-environment virtual host files and separate SSL certificate files. Previously everything was placed in one file.

### Changed

- Added empty line to the end of the Magneto 2.4.1 `.gitignore` (`magento:setup` added custom ignores in the wrong way).


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