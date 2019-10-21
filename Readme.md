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

# Fill in your root password here so that the tool can change permissions and add entries to your hosts file
echo 'USER_ROOT_PASSWORD=<your_root_password>' > /misc/apps/dockerizer_for_php/.env.local

# Install Magento 2 (PHP 7.2 by default) with self-signed SSL certificate that is valid for you. Add it to the hosts file. Just launch in browser when completed!
php /misc/apps/dockerizer_for_php/bin/console setup:magento example-232.local 2.3.2 -nf
```


## Preparing the tool ##

Works best without additional adjustments with systems installed by the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts).

To use this application, you must switch to PHP 7.1 or 7.2. Magento installation happens inside the Docker container, so do not worry about its' system requirements. 

After cloning the repository (if you haven't run the commands mentioned above):
1) copy `./config/auth.json.sample` file to `./config/auth.json` and enter your credentials instead of the placeholders;
2) add your root password to the file `.env.local` (in the root folder of this app): `USER_ROOT_PASSWORD=<your pass here>`
3) run `composer install`

Other settings can be found in the file `.env.dist`. There you can find database connection settings and information
about the folders where projects and SSL keys are stores. Move these environment settings to your `.env.local` file
if you need to customize them (especially database connection settings like DATABASE_HOST and DATABASE_PORT).


## Install Magento 2 ##

The setup:magento command deploys clean Magento instance of the selected version into the defined folder.
You will be asked to select PHP version if it has not been provided.

Simple usage from any location:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento magento-232.local 2.3.2
```

Install Magento with the pre-defined PHP version:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento magento-232.local 2.3.2 --php=7.2
```

Force install/reinstall Magento with the latest supported PHP version, without questions, erase the previous installation if the folder exists:

```bash
php /misc/apps/dockerizer_for_php/bin/console setup:magento magento-232.local 2.3.2 -n -f
```

IMPORTANT!!! Do not enter the root password when you see the command asks for it. This is just because it works in the interactive mode and shows all input!


### Result ###

You will get:

- all website-related files are in the folder `/misc/apps/<domain>/';
- Docker container with Apache up and running;
- MySQL database with the name, user and password based on the domain name (e.g. `magento_232_local` for domain `magento-232.local`);
- Magento 2 installed without Sample Data (can be installed from inside the container if needed). Web root and respective configurations are set to './pub/' folder;
-  Admin Panel path is `admin` - like https://example.com/admin/
- Admin Panel login/password are: `development` / `q1w2e3r4`;
- self-signed SSL certificate;
- reverse-proxy automatically configured to serve this container and handle SSL certificate;
- domain(s) added to your `/etc/hosts` if not there yet (this is why your root password should be added to `.env.local`).


### Enter container, install Sample Data ###

By default, container name equals to the domain you enter. See container name in existing configurations in `docker-compose.yml`

```bash
# here "example.com" is a container name example
docker exec -it example.com bash
php bin/magento sampledata:deploy
php bin/magento setup:upgrade
php bin/magento indexer:reindex
exit
```


## Dockerize existing PHP applications ##

The `dockerize` command copies Docker files to the current folder and updates them as per project settings. You will be asked to enter production/development domains, choose PHP version and web root folder.
Development domains can be left empty if they are not needed.
If you made a mistype in the PHP version or domain names - just re-run the command, it will overwrite existing Docker files.

Example usage in the interactive mode:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize
```

Example usage without development domains:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com' --dev=''
```

Example usage with development domains:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize --php=7.2 --prod='example.com www.example.com example-2.com www.example-2.com' --dev='example-dev.com www.example-dev.com example-2-dev.com www.example-2-dev.com'
```

Magento 1 example with custom web root:

```bash
php /misc/apps/dockerizer_for_php/bin/console dockerize --php=5.6 --prod='example.com www.example.com' --dev='' --webroot='/'
```

Docker containers are not run automatically, so you can still edit configurations before running them. The file `/etc/hosts` is not populated automatically.


## Starting and stopping compositions in development mode ##

Stop composition:

```bash
docker-compose -f docker-compose.yml -f docker-compose-dev.yml down
```

Start composition, especially after making any changed to .yml files:

```bash
docker-compose -f docker-compose.yml -f docker-compose-dev.yml up -d --force-recreate
```

Rebuild container if Dockerfile was changed:

```bash
docker-compose -f docker-compose.yml -f docker-compose-dev.yml up -d --force-recreate --build
```

Please, refer the Docker and docker-compose documentation  for more information on docker commands.


## Generating SSL certificates ##

Manually generated SSL certificates must be places in `/misc/share/ssl/`. This folder is linked to Docker containers and
can be shared with VirtualBox or other virtualization tools if needed.

If the SSL certificates are not valid in Chrome/Firefox when you first run Magento then run the following command and restart the browser:

```bash
mkcert -install
```

## Author and maintainer ##

[Maksym Zaporozhets](mailto:maksimz@default-value.com)

[Magento profile](https://u.magento.com/certification/directory/dev/180177/)