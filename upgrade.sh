# Upgrade to PHP 8.0 (use 8.1 if possible)
sudo apt purge php7.4-* -y
sudo apt remove composer -y

sudo apt install \
    php8.0-bz2 \
    php8.0-cli \
    php8.0-common \
    php8.0-curl \
    php8.0-mbstring \
    php8.0-mysql \
    php8.0-opcache \
    php8.0-readline \
    php8.0-ssh2 \
    php8.0-xml \
    php8.0-xdebug \
    php8.0-zip \
    --no-install-recommends -y
sudo apt install composer -y

   printf "\n>>> Creating ini files for the development environment >>>\n"
IniDirs=/etc/php/*/*/conf.d/
for IniDir in ${IniDirs};
do
    printf "Creating ${IniDir}/999-custom-config.ini\n"
sudo rm ${IniDir}999-custom-config.ini
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

php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer


# @TODO: update coding standards repo as well
cd ${PROJECTS_ROOT_DIR}dockerizer_for_php/
cd ${PROJECTS_ROOT_DIR}dockerizer_for_php/

# Allow using `chown` command and writing /etc/hosts file by the current user
# This is unsafe, but probably better than keeping the root password in a plain text file
sudo setfacl -m $USER:rw /etc/hosts
