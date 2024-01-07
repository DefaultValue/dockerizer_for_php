# Dockerizer. Easy create Docker compositions for your apps. #

Dockerizer is a tool for easy creation and management of templates for Docker compositions for your PHP applications.
You can use it for development or in the CI/CD pipelines.

Install any Magento 2 version in 1 command. Add Docker files to your existing projects in one command.
Install all PHP development software with a single script from the [Ubuntu post-installation scripts](https://github.com/DefaultValue/ubuntu_post_install_scripts) repository.

**See [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki) for installation instructions and other documentation.**

## From clean Ubuntu to deployed Magento 2 in just 4 commands ##

```bash
# This file is from the `Ubuntu post-installation scripts` repository
# https://github.com/DefaultValue/ubuntu_post_install_scripts
# Reboot happens automatically after pressing any key in the terminal after executing a script. This MUST be done before moving forward!
sh ubuntu_22.04_x64.sh

# Fill in your `auth.json` file for Magento 2. You can add other credentials there to use this tool for any other PHP apps
cp ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json.sample ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json
subl ${PROJECTS_ROOT_DIR}dockerizer_for_php/config/auth.json

# Install Magento 2 with self-signed SSL certificate. Add it to the hosts file. Just launch in browser when completed!
php ${PROJECTS_ROOT_DIR}dockerizer_for_php/bin/dockerizer magento:setup 2.4.6
```

**See [Wiki](https://github.com/DefaultValue/dockerizer_for_php/wiki) for installation instructions, MacOS support and other documentation.**

## Traffic routing and containers isolation ##

The below schema shows how the network traffic is routed from the host machine to the containers and back.

![Infrastructure schema](https://raw.githubusercontent.com/DefaultValue/dockerizer_for_php/master/docker_infrastructure_schema.png)

## Release notes, presentations and videos ##

- Dockerizer v3.0.0 released! [Check the presentation for more information](https://docs.google.com/presentation/d/1jLC1yaabB9bFh_4nnQZYGwHmVe8Vit6OgAsBjIjEKog/edit?usp=sharing) and in the [Video](https://www.youtube.com/watch?v=88fCLnOnLvA)

## Developing Dockerizer ##

Dockerfile in the project root allows to install and update composer packages using the lowest supported PHP version
even if your local version is much higher:

```bash
docker run --name dockerizer-app --rm -it --user 1000:1000 -v "$PWD":/app -w /app $(docker build -q .) composer install
```

We plan to use this or similar image to pack Dockerizer inside Docker. This way it will be easier to use it for CI/CD
and developers will not even need PHP to run it locally.

## Code quality checks ##

```bash
# Level 9 is too much as it forces to make changes that contradict the "Let it fail" principle.
# In this case we prefer the app to fail rather then convert all data types and still work.
php -d xdebug.mode=off ./vendor/bin/phpstan analyse -l 8 ./src/
php -d xdebug.mode=off ./vendor/bin/phpcs --standard=PSR12 --severity=1 --colors ./src/
```

## Author and maintainer ##

[Maksym Zaporozhets](mailto:maksimz@default-value.com)

[Magento profile](https://u.magento.com/certification/directory/dev/180177/)