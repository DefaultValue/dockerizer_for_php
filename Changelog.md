# Changelog

All notable changes to this project will be documented in this file since v2.0.0

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2020-MM-DD

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

- Commands `setup:magento` and `dockerize` now create only one environment called `prod`.
- Better default parameters handling.

### Removed

- Symfony components `symfony/framework-bundle` and `symfony/dependency-injection`.
- Replaced `--prod` and `--dev` options with `--domains` option from the `\App\CommandQuestion\Question\Domains` class.