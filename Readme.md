# CAUTION! #

This Readme is pretty much deprecated. We're actively working on adding more detailed documentation to the [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki).

Please, try the code from the `3.2.0-development` branch and refer to the Wiki first.


# Dockerizer. Easy create compositions for your apps #

This is a part of the local infrastructure project which aims to create easy to install and use environment
for application development based on Ubuntu LTS. Dockerizer can be used as a standalone application.

1. [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts) - install software,
clone repositories with `Docker infrastructure` and `Dockerizer for PHP` tool. Infrastructure is launched automatically
during setup and you do not need start it manually. Check this repo to get more info about what software is installed,
where the files are located and why we think this software is needed.

2. [Docker infrastructure](https://github.com/DefaultValue/docker_infrastructure) - run [Traefik](https://traefik.io/)
reverse-proxy container with linked MySQL 5.6, 5.7, MariaDB 10.1, 10.3, phpMyAdmin and Mailhog containers.
Infrastructure is cloned and run automatically by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts).
Check this repository for more information on how the infrastructure works, how to use xDebug, LiveReload etc.

3. `Dockerizer for PHP` (this repository) - install any Magento 2 version in 1
command. Add Docker files to your existing PHP projects in one command. This repository is cloned automatically
by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts). Read below
to get more information on available commands and what this tool does.

Dockerizer v3.0.0 released! [Please, check the presentation for more information](https://docs.google.com/presentation/d/1jLC1yaabB9bFh_4nnQZYGwHmVe8Vit6OgAsBjIjEKog/edit?usp=sharing)
and in the [Video](https://www.youtube.com/watch?v=88fCLnOnLvA)


## From clean Ubuntu to deployed Magento 2 in 4 commands ##

```bash
# This file is from the `Ubuntu post-installation scripts` repository
# https://github.com/DefaultValue/ubuntu_post_install_scripts
# Reboot happens automatically after pressing any key in the terminal after executing a script. This MUST be done before moving forward!
sh ubuntu_20.04.sh

# Fill in your `auth.json` file for Magento 2. You can add other credentials there to use this tool for any other PHP apps
cp ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json.sample ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json
subl ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json

# Install Magento 2 (PHP 7.2 by default) with self-signed SSL certificate that is valid for you. Add it to the hosts file. Just launch in browser when completed!
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.4
```

MacOS is currently not supported due to the networking issues. Support will be added soon.


## Preparing the tool ##

Works best without additional adjustments with systems installed by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts). Treafik must be up and running (see Docker Infrastructure).

To use this application, you must have PHP 8.1 or 8.1 installed. Magento installation happens inside the Docker container, so do not worry about its system requirements.

Application required two environment variables for you shell  that contain projects root dir and dir to store SSL certificates. Bash example:

```
echo "
export PROJECTS_ROOT_DIR=\${HOME}/misc/apps/
export SSL_CERTIFICATES_DIR=\${HOME}/misc/certs/
" > ~/.bash_aliases
```

After cloning the repository (if you haven't run the commands mentioned above):
1) copy `./config/auth.json.sample` file to `./config/auth.json` and enter your credentials instead of the placeholders;
2) run the following: `echo "TRAEFIK_SSL_CONFIGURATION_FILE=${PROJECTS_ROOT_DIR}docker_infrastructure/local_infrastructure/configuration/certificates.toml" > ${PROJECTS_ROOT_DIR}dockerizer_for_php/.env.local`
3) run `composer install`

Other settings can be found in the file `.env.dist`. There you can find default database container name and information
about the folders where projects and SSL keys are stores. Move these environment settings to your `.env.local` file
if you need to customize them (especially database connection settings like DATABASE_HOST and DATABASE_PORT).


## Install Magento 2 ##

The `magento:setup` command deploys a clean Magento instance of the selected version.
You will be asked to choose composition template, enter domains, choose required and optional services.

Simple usage from any location:

```bash
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.4
```

Force install/reinstall Magento with pre-defined parameters, and erase the previous installation if the folder exists:

```bash
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.4 -f -v \
    --domains="magento-244-p81-c1-nva.local www.magento-244-p81-c1-nva.local" \
    --template="magento_2.4.4_nginx_varnish_apache" \
    --required-services="php_8_1_apache,mysql_8_0_persistent,elasticsearch_7_16_3_persistent" \
    --optional-services="redis_6_2"
```

#### Result ####

You will get:

- all website-related files are in the folder `~/misc/apps/<domain>/`;
- docker-compose composition up and running (without dev tools);
- docker-compose files are located in the `.dockerizer` folder of the project;
- Magento 2 installed without Sample Data (can be installed from inside the container if needed). Web root and respective configurations are set to the './pub/' folder;
- self-signed SSL certificate;
- reverse-proxy automatically configured to serve this container and handle SSL certificate;
- domain(s) added to your `/etc/hosts` if not there yet (this is why your root password should be added to `.env.local`).
- Admin Panel path is displayed at the end of installing Magento;
- Admin Panel login/password are: `development` / `q1w2e3r4` (custom passwords will be added soon);
- Magento is configured to use Varnish and Elasticsearch. Redis is not configured automatically!

Be default two docker-compose files are generated:
- `docker-compose.yml` - basic configuration that reflects production environment and can be used in the build environment;
- `docker-compose-prod.yml` - development settings for production environment (xDebug, )

#### Enter container, install Sample Data ####

```bash
# here "example.com" is an example container name
docker exec -it <php_container_id> bash
php bin/magento sampledata:deploy
php bin/magento setup:upgrade
php bin/magento indexer:reindex
exit
```


## Dockerize existing applications ##

The `composition:build-from-template` compiles composition from template. You will be asked to enter domains, choose PHP
and other containers, etc.

Example usage in the fully interactive mode:

```bash
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer composition:build-from-template
```

Default environment is maned `prod`. Use option `with-enviroment='<env_name>` to set any other name.

For Magento 1 or application with different web root use `with-web_root=''` or other path.

The file `/etc/hosts` is automatically updated with new domains. Traefik configuration is updated with the new SSL certificates.

Docker containers are not run automatically, so you can still edit configurations before running them.


## Using a custom Dockerfile ##

You can use custom Dockerfile based on the DockerHub Images if needed.

Example `docker-compose.yaml` fragment (use `build` instead of `image`):

```yml
services:
  php-apache:
    container_name: example.com
    build:
      context: .
      dockerfile: docker/Dockerfile
```

Custom Dockerfile start:

```Dockerfile
ARG EXECUTION_ENVIRONMENT
FROM defaultvalue/php:8.1-development
```


## Starting and stopping compositions in development mode ##

Please, refer the Docker and docker-compose documentation for information on docker commands.

Restart composition:

```bash
docker-compose down
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml up -d --force-recreate
```

Rebuild container if Dockerfile was changed:

```bash
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml up -d --force-recreate --build
```


#### CAUTION! ####

It is not to reuse the same domain name and not to try running multiple identical compositions at once. Compositions will not start or there will be a mess.


## Modules installation testing - to be implemented ##

The `magento:test-module-install` command allows testing module installation on the existing Magento 2 instance.
Command will clear and reinstall the existing Magento instance. Use option `together` or short `t` if it is required to test installing modules together with Magento itself and Sample Data modules.

Usages:

```bash
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:instal-module /folder/to/modules
```

To copy modules prior to installing Magento 2 use the option `together` or short `t`:

```bash
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer module:instal-module /folder/to/modules -t
```


## Generating SSL certificates ##

Manually generated SSL certificates must be places in `~/misc/certs/` or other folder defined in the
`SSL_CERTIFICATES_DIR` environment variable (see below about variables). This folder is linked to a Docker containers
with Traefik and with web server. This can be shared with VirtualBox or other virtualization tools if needed.

If the SSL certificates are not valid in Chrome/Firefox when you first run Magento then run the following command and restart the browser:

```bash
mkcert -install
```


## Helpful Aliases ##

A number of helpful aliases are added to your `~/.bash_aliases` file if you use the Ubuntu post-installation script.
They make using Docker and Dockerizer even easier. See [Aliases](https://github.com/DefaultValue/ubuntu_post_install_scripts#aliases)
Run these aliases from the project folder with docker-compose files. You can take code for these aliases from that scripts.


## Manual installation (Ubuntu-like and MacOS) ##

Manually clone infrastructure and Dockerizer repositories to the `~/misc/apps/` folder. The folder `~/misc/certs/`
must be created as well. Set required environment variables like this (use `~/.bash_profile` for MacOS):

```bash
echo "
export PROJECTS_ROOT_DIR=${HOME}/misc/apps/
export SSL_CERTIFICATES_DIR=${HOME}/misc/certs/
```

All other commands must be executed taking this location into account, e.g. like this:

```bash
cp ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json.sample ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.4 --domains="example.com www.example.com"
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer composition:build-from-template
```


## Environment variables explained ##

- `PROJECTS_ROOT_DIR` - location of your projects. All new projects are deployed here. Some commands will not work out of this directory for security reasons. We don't want to wipe your PC.
- `SSL_CERTIFICATES_DIR` - directory with certificates to mount to the web server container and Traefik reverse-proxy;


## Supported MySQL images ##

For now, we support only the following images:
- [mysql](https://hub.docker.com/_/mysql)
- [mariadb](https://hub.docker.com/_/mariadb)
- [bitnami/mariadb](https://hub.docker.com/r/bitnami/mariadb)

The sign `$` is automatically changed to `$$`. Be sure to do this if you manually change password in the
`docker-compose*.yaml` file.


## Composer install ##

Dockerfile in the project root allows to install and update composer packages using the lowest supported PHP version
even if your local version is much higher:

```bash
docker run --name dockerizer-app --rm -it --user 1000:1000 -v "$PWD":/app -w /app $(docker build -q .) composer install
```

## Code quality checks

```bash
# Level 9 is too much as it forces to make changes that contradict the "Let it fail" principle.
# In this case we prefer the app to fail rather then convert all data types and still work.
php -d xdebug.mode=off ./vendor/bin/phpstan analyse -l 8 ./src/
php -d xdebug.mode=off ./vendor/bin/phpcs --standard=PSR12 --severity=1 --colors ./src/
```


## For MacOS Users ##

MacOS is currently not supported due to inability to use `host` network mode. Working on changing network mode to `bridge`,
which will require connecting all compositions to Traefik. Pull requests appreciated if they do not bring more complexity for developers.


## Author and maintainer ##

[Maksym Zaporozhets](mailto:maksimz@default-value.com)

[Magento profile](https://u.magento.com/certification/directory/dev/180177/)