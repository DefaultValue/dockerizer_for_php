# Dockerizer. Easy create Docker compositions for your apps

Dockerizer is a tool for easy creation and management of templates for Docker
compositions for your PHP applications. You can use it for development or in the
CI/CD pipelines.

- Add Docker files to your existing projects in one command
- Install Magento >=2.4.2 in one command
- Install all PHP development software with a single script from the
  [Ubuntu post-installation scripts](https://github.com/maksymz/ubuntu_post_install_scripts)
  repository.

**See [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki) for
installation instructions, macOS support and extended documentation.**

## From clean Ubuntu to deployed Magento 2 in just 4 commands

```bash
# This file is from the `Ubuntu post-installation scripts` repository
# https://github.com/maksymz/ubuntu_post_install_scripts
# Reboot happens automatically after pressing any key in the terminal after executing a script. This MUST be done before moving forward!
sh ubuntu_24.04_x64.sh

# Fill in your `auth.json` file for Magento 2. You can add other credentials there to use this tool for any other PHP apps
cp ${DOCKERIZER_PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json.sample ${DOCKERIZER_PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json
subl ${DOCKERIZER_PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json

# Install Magento 2 with self-signed SSL certificate. Add it to the hosts file. Just launch in browser when completed!
php ${DOCKERIZER_PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.6
```

**See [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki) for
installation instructions, MacOS support and extended documentation.**

## Traffic routing and containers isolation

The below schema shows how the network traffic is routed from the host machine
to the containers and back.

![Infrastructure schema](https://raw.githubusercontent.com/DefaultValue/dockerizer_for_php/master/docker_infrastructure_schema.png)

## System requirements

- PHP >=8.0.2
- Composer >=2.0
- Docker or Docker Desktop
- Docker compose v2

Dev dependencies:

- NodeJS >=24.0.0 for `husky`, `lint-staged`, etc. (optional, only for
  Dockerizer development)

### Upgrading Dockerizer

- [Upgrade from v3.3.x to 3.4.x](https://github.com/DefaultValue/dockerizer_for_php/wiki/Upgrade-from-Dockerizer-3-3-to-3-4)

---

## Developing Dockerizer

`composer.json` sets `config.platform.php` to the lowest supported PHP version
(8.2.0). This ensures `composer install` and `composer update` resolve
dependencies compatible with PHP 8.2+ regardless of your local PHP version.

## Code quality checks

```bash
# Level 9 is too much as it forces to make changes that contradict the "Let it fail" principle.
# In this case we prefer the app to fail rather then convert all data types and still work.
php -d xdebug.mode=off ./vendor/bin/phpstan analyse -l 8 ./src/
php -d xdebug.mode=off ./vendor/bin/phpcs --standard=PSR12 --severity=1 --colors ./src/
```

## Author and maintainer

[Maksym Zaporozhets](mailto:maksimzaporozhets@gmail.com)
