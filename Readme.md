# Dockerize PHP applications. Install Magento with a single command. #

This is a part of the local infrastructure project which aims to create easy to install and use environment for PHP development based on Ubuntu LTS.

1. [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts) - install software,
clone repositories with `Docker infrastructure` and `Dockerizer for PHP` tool. Infrastructure is launched automatically
during setup and you do not need start it manually. Check this repo to get more info about what software is installed,
where the files are located and why we think this software is needed.

2. [Docker infrastructure](https://github.com/DefaultValue/docker_infrastructure) - run [Traefik](https://traefik.io/)
reverse-proxy container with linked MySQL 5.6, 5.7 and phpMyAdmin containers. Infrastructure is cloned and run automatically by the
[Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts). Check this repository
for more information on how the infrastructure works, how to use xDebug, LiveReload etc.

3. `Dockerizer for PHP` (this repository) - install any Magento 2 version in 1
command. Add Docker files to your existing PHP projects in one command. This repository is cloned automatically
by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts). Read below
to get more information on available commands and what this tool does.


## From clean Ubuntu to deployed Magento 2 in 5 commands ##

```bash
# This file is from the `Ubuntu post-installation scripts` repository
# https://github.com/DefaultValue/ubuntu_post_install_scripts
# Reboot happens automatically after pressing any key in the terminal after executing a script. This must be done before moving forward 
sh ubuntu_18.04_docker.sh

# Fill in your `auth.json` file for Magento 2. You can add other credentials there to use this tool for any other PHP apps
cp /misc/apps/dockerizer_for_php/config/auth.json.sample /misc/apps/dockerizer_for_php/config/auth.json
subl /misc/apps/dockerizer_for_php/config/auth.json

# Fill in your root password here so that the tool can change permissions and add entries to your /etc/hosts file
echo 'USER_ROOT_PASSWORD=<your_root_password>' > /misc/apps/dockerizer_for_php/.env.local

# Install Magento 2 (PHP 7.2 by default) with self-signed SSL certificate that is valid for you. Add it to the hosts file. Just launch in browser when completed!
php /misc/apps/dockerizer_for_php/bin/console setup:magento example-232.local 2.3.2 -nf
```

See notes for MacOS users at the bottom.


## Preparing the tool ##

Works best without additional adjustments with systems installed by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts).

To use this application, you must switch to PHP 7.3 or 7.4. Magento installation happens inside the Docker container, so do not worry about its' system requirements.

After cloning the repository (if you haven't run the commands mentioned above):
1) copy `./config/auth.json.sample` file to `./config/auth.json` and enter your credentials instead of the placeholders;
2) add your root password to the file `.env.local` (in the root folder of this app): `USER_ROOT_PASSWORD=<your pass here>`
3) run `composer install`

Other settings can be found in the file `.env.dist`. There you can find default database container name and information
about the folders where projects and SSL keys are stores. Move these environment settings to your `.env.local` file
if you need to customize them (especially database connection settings like DATABASE_HOST and DATABASE_PORT).


## Install Magento 2 ##

The setup:magento command deploys clean Magento instance of the selected version into the defined folder.
You will be asked to select PHP version, MySQL container and domains if they have not been provided.

Simple usage from any location:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento 2.3.4
```

Install Magento with the pre-defined PHP version:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento 2.3.4 --php=7.2
```

Force install/reinstall Magento with the latest supported PHP version, with default MySQL container, without questions.
This erases the previous installation if the folder exists:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento 2.3.4 --domains="example.local www.example.local" -nf
```

#### Result ####

You will get:

- all website-related files are in the folder `/misc/apps/<domain>/`;
- Docker container with Apache up and running;
- MySQL database with the name, user and password based on the domain name (e.g. `magento_234_local` for domain `magento-234.local`);
- Magento 2 installed without Sample Data (can be installed from inside the container if needed). Web root and respective configurations are set to './pub/' folder;
- self-signed SSL certificate;
- reverse-proxy automatically configured to serve this container and handle SSL certificate;
- domain(s) added to your `/etc/hosts` if not there yet (this is why your root password should be added to `.env.local`).
- Admin Panel path is `admin` - like https://magento-234.local/admin/
- Admin Panel login/password are: `development` / `q1w2e3r4`;

Be default two docker-compose files are generated:
- `docker-compose.yml` - basic configuration that reflects production environment and can be used in the build environment;
- `docker-compose-prod.yml` - development settings for production environment (xDebug, )

#### Enter container, install Sample Data ####

By default, container name equals to the domain you enter. See container name in existing configurations in `docker-compose.yml`

```bash
# here "example.com" is an example container name
docker exec -it example.com bash
php bin/magento sampledata:deploy
php bin/magento setup:upgrade
php bin/magento indexer:reindex
exit
```


## Dockerize existing PHP applications ##

The `dockerize` command copies Docker files to the current folder and updates them as per project settings.
You will be asked to enter domains, choose PHP version, MySQL container and web root folder.
If you a mistype a PHP version or domain names - just re-run the command, it will overwrite existing Docker files.

Example usage in the fully interactive mode:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize
```

Example full usage with all parameters:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --mysql-container=mariadb103 --webroot='pub/' --domains='example.com www.example.com example-2.com www.example-2.com'
```

Magento 1 example with the custom web root:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize --php=5.6 --mysql-container=mysql56 --webroot='/' --domains='example.com www.example.com'
```

The file `/etc/hosts` is automatically updated with new domains. Traefik configuration is updated with the new SSL certificates.

Docker containers are not run automatically, so you can still edit configurations before running them.


## Starting and stopping compositions in development mode ##

Stop composition:

```bash
docker-compose -f docker-compose.yml -f docker-compose-prod.yml down
```

Start composition, especially after making any changed to .yml files:

```bash
docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --force-recreate
```

Rebuild container if Dockerfile was changed:

```bash
docker-compose -f docker-compose.yml -f docker-compose-prod.yml up -d --force-recreate --build
```

Please, refer the Docker and docker-compose documentation  for more information on docker commands.


## Adding more environments ##

We often need more then just a production environment - staging, test, development etc. Use the following command to
add more environments to your project:

```bash
php /misc/apps/dockerizer_for_php/bin/console env:add <env_name>
```

This will:
- copy the `docker-compose-dev.yml` template and rename it (for example, to `docker-compose-staging.yml`);
- modify the `mkcert` information string in the `docker-compose.file`;
- generate new SSL certificates for all domains from the `docker-compose*.yml` files;
- reconfigure `Traefik` and `virtual-host.conf`, update `.htaccess`;
- add new entries to the `/etc/hosts` file if needed.

Container name is based on the main (actually, the first) container name from the `docker-compose.yml`
file suffixed with the `-<env_name>`. This allows running multiple environments at the same time.

Composition is not restarted automatically, so you can edit everything finally running it.

#### CAUTION! ####

1) SSL certificates are not specially prefixed! If you add two environments in different folders (let's say
`dev` and `staging`) then the certificates will be overwritten for one of them.
Instead of manually configuring the certificates you can first copy new `docker-compose-dev.yml`
to the folder where you're going to add new `staging` environment.

2) If your composition runs other named services (e.g., those that have `container_name`)
then you'll have to rename them manually by moving those services to the new environment file and changing
the container name like this is done for the PHP container. You're welcome to automate this as well.


## Hardware and build testing ##

The `hardware:test` sets up Magento and perform a number of tasks to test environment:
- build images to warm up Docker images cache because they aren't on the Dockerhub yet;
- install Magento 2 (2.0.18 > PHP 5.6, 2.1.18 > PHP 7.0, 2.2.11 > PHP 7.1, 2.3.2 > PHP 7.2, 2.3.4 > PHP 7.3);
- commit Docker files;
- test Dockerizer's `env:add` - stop containers, dockerize with another domains, add env, and run composition;
- run `deploy:mode:set production`;
- run `setup:perf:generate-fixtures` to generate data for performance testing
(medium size profile for v2.2.0+, small for previous version because generating data takes too much time);
- run `indexer:reindex`.

Usage for hardware test and Dockerizer self-test (install all instances and ensure they work fine):

    <info>php bin/console %command.full_name%</info>

Log files are written to `dockerizer_for_php/var/hardware_test_results/`.


## Generating SSL certificates ##

Manually generated SSL certificates must be places in `/misc/share/ssl/`. This folder is linked to Docker containers and
can be shared with VirtualBox or other virtualization tools if needed.

If the SSL certificates are not valid in Chrome/Firefox when you first run Magento then run the following command and restart the browser:

```bash
mkcert -install
```


## Helpful Aliases ##

Run these aliases from the project folder with docker-compose files.
These and other aliases are already added to your `~/.bash_aliases` file if you use Ubuntu post-installation script.
Assuming you have only one container with the command `docker-php-entrypoint`.

```bash
# Enter container
alias BASH='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER bash'
# Clean cache
alias CC='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER php bin/magento cache:clean'
# Run setup:upgrade
alias SU='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER php bin/magento setup:upgrade'
# Run indexer:reindx
alias DI='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER php bin/magento setup:di:compile'
# Run setup:di:compile
alias DI='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER php bin/magento setup:di:compile'
# Generate URN catalog. @TODO: check if replaving /var/www/html is needed
alias URN='CONTAINER=`docker-compose ps | grep docker-php-entrypoint | cut -d " " -f1` ; docker exec -it $CONTAINER php bin/magento dev:urn-catalog:generate .idea/misc.xml; sed -i "s/\/var\/www\/html/\$PROJECT_DIR\$/g" .idea/misc.xml'
```


## For MacOS Users ##

Manually clone infrastructure and dockerizer repositories.
Since MacOS Catalina it is not possible to create folder in the filesystem root. So, all repositories should be cloned
to the `~/misc/apps/` folder instead. The folder `/misc/share/ssl` must be created as well. Additionally to the fourth
command to that sets your root password you should also run:

```bash
echo "
PROJECTS_ROOT_DIR=/Users/$USER/misc/apps/
SSL_CERTIFICATES_DIR=/Users/$USER/misc/share/ssl/" >> ~/misc/apps/dockerizer_for_php/.env.local
```

All commands must be executed taking into account this new location, e.g. like this:

```bash
cp ~/misc/apps/dockerizer_for_php/config/auth.json.sample ~/misc/apps/dockerizer_for_php/config/auth.json
php ~/misc/apps/dockerizer_for_php/bin/console setup:magento magento-232.local 2.3.2
php ~/misc/apps/dockerizer_for_php/bin/console dockerize
```

@TODO: write how to run containers and what should be changed in the docker-compose* files on Mac.
@TODO: MacOS support is experimental and require additional testing. Will be tested more and improved in the future releases.


## Author and maintainer ##

[Maksym Zaporozhets](mailto:maksimz@default-value.com)

[Magento profile](https://u.magento.com/certification/directory/dev/180177/)