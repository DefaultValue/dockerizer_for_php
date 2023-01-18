#!/bin/bash

#
# Copyright (c) Default Value LLC.
# This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
# Do not change this file if you want to upgrade the tool to the newer versions in the future
# Please, contact us at https://default-value.com/#contact if you wish to customize this tool
# according to you business needs
#

set -e

sudo apt purge php* -y
sudo rm -rf /etc/php/ || true
sudo apt purge nodejs -y

sudo apt update
sudo apt upgrade -y
sudo apt autoremove -y

# Allow using `chown` command and writing /etc/hosts file by the current user
# This is unsafe, but probably better than keeping the root password in a plain text file
sudo setfacl -m $USER:rw /etc/hosts

# used by some aliases to copy password to clipboard
sudo apt install xclip -y

# === Upgrade NodeJS ===
curl -fsSL https://deb.nodesource.com/setup_16.x | sudo -E bash -
sudo apt update
sudo apt install nodejs -y

# === Upgrade to 8.1 ===
sudo apt install \
    php8.1-bz2 \
    php8.1-cli \
    php8.1-common \
    php8.1-curl \
    php8.1-intl \
    php8.1-mbstring \
    php8.1-mysql \
    php8.1-opcache \
    php8.1-readline \
    php8.1-ssh2 \
    php8.1-xml \
    php8.1-xdebug \
    php8.1-zip \
    --no-install-recommends -y
sudo apt remove composer -y
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/bin/composer

   printf "\n>>> Creating ini files for the development environment >>>\n"
IniDirs=/etc/php/*/*/conf.d/
for IniDir in ${IniDirs};
do
    printf "Creating ${IniDir}999-custom-config.ini\n"
sudo rm -f ${IniDir}999-custom-config.ini
echo "error_reporting=E_ALL & ~E_DEPRECATED
display_errors=On
display_startup_errors=On
ignore_repeated_errors=On
cgi.fix_pathinfo=1
max_execution_time=3600
session.gc_maxlifetime=84600
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=1
opcache.max_wasted_percentage=10
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
xdebug.mode=debug
xdebug.remote_handler=dbgp
xdebug.discover_client_host=0
xdebug.show_error_trace=1
xdebug.start_with_request=yes
xdebug.max_nesting_level=256
xdebug.log_level=0
" | sudo tee ${IniDir}999-custom-config.ini > /dev/null
done

IniDirs=/etc/php/*/cli/conf.d/
for IniDir in ${IniDirs};
do
echo "memory_limit=2G
" | sudo tee -a ${IniDir}999-custom-config.ini >> /dev/null
done

# === Upgrade Docker infrastructure files ===
cd ${PROJECTS_ROOT_DIR}docker_infrastructure/
git config core.fileMode false
git reset --hard HEAD
git pull origin master --no-rebase
# Refresh all images if outdated, pull if not yet present
docker pull traefik:v2.2
docker pull mysql:5.6
docker pull mysql:5.7
docker pull mysql:8.0
docker pull bitnami/mariadb:10.1
docker pull bitnami/mariadb:10.2
docker pull bitnami/mariadb:10.3
docker pull bitnami/mariadb:10.4
docker pull phpmyadmin/phpmyadmin
docker pull mailhog/mailhog:v1.0.1
# Run with sudo before logout, but use current user's value for SSL_CERTIFICATES_DIR
cd ${PROJECTS_ROOT_DIR}docker_infrastructure/local_infrastructure/
docker-compose up -d --force-recreate

echo "
127.0.0.1 phpmyadmin.docker.local
127.0.0.1 traefik.docker.local
127.0.0.1 mailhog.docker.local" | sudo tee -a /etc/hosts

# === Upgrade Dockerizer for PHP ===
cd ${PROJECTS_ROOT_DIR}dockerizer_for_php/
git config core.fileMode false
git reset --hard HEAD
git checkout master
git pull origin master --no-rebase
composer install

echo "TRAEFIK_SSL_CONFIGURATION_FILE=${PROJECTS_ROOT_DIR}docker_infrastructure/local_infrastructure/configuration/certificates.toml" > ${PROJECTS_ROOT_DIR}dockerizer_for_php/.env.local

# === Upgrade Magento Coding Standard if exists ===
if test -d "${PROJECTS_ROOT_DIR}magento-coding-standard"; then
    cd ${PROJECTS_ROOT_DIR}magento-coding-standard/
    git config core.fileMode false
    git reset --hard HEAD
    git checkout master
    git pull origin master --no-rebase
    composer install
    npm install
fi

# Older images have not being updated for quite a long time
docker pull defaultvalue/php:7.1-development
docker pull defaultvalue/php:7.1-production
docker pull defaultvalue/php:7.2-development
docker pull defaultvalue/php:7.2-production
docker pull defaultvalue/php:7.3-development
docker pull defaultvalue/php:7.3-production
docker pull defaultvalue/php:7.4-development
docker pull defaultvalue/php:7.4-production
docker pull defaultvalue/php:8.0-development
docker pull defaultvalue/php:8.0-production
docker pull defaultvalue/php:8.1-development
docker pull defaultvalue/php:8.1-production