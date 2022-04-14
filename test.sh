#!/bin/bash
set -e

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.0.18 -f \
#  --template="magento_2.0.2-2.0.18_apache" \
#  --runner="php_5.6_apache" \
#  --required-services="mariadb_10.1_persistent" \
#  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
#  --domains='test-apache.local www.test-apache.local'

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -f \
#  --template="magento_2.1_apache" \
#  --runner="php_7.0_apache" \
#  --required-services="mariadb_10.2_persistent" \
#  --optional-services="redis_5.0" \
#  --domains='test-apache.local www.test-apache.local'

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -f \
#  --template="magento_2.1_nginx_varnish_apache" \
#  --required-services="php_7.0_apache,mysql_5.7_persistent" \
#  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
#  --domains='test-apache.local www.test-apache.local'

# Magento 2.1.18 > PHP 7.0 > Composer 1 > Nginx + Varnish + Apache
# @TODO: still asking for optional services in the non-interactive mode!
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -n -f \
  --domains="test-2118-p70-nva.local www.test-2118-p70-nva.local" \
  --template="magento_2.1_nginx_varnish_apache" \
  --required-services="php_7_0_apache,mysql_5_7_persistent" \
  --optional-services=""
cd ~/misc/apps/test-2118-p70-nva.local/.dockerizer/test-2118-p70-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes

# Magento 2.4.4 > PHP 8.1 > Composer 2 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c2-apace.local www.test-244-p81-c2-apache.local"
  --template="magento_2.4.4_apache" \
  --runner="php_8_1_apache" \
  --required-services="mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p81-c2-apace.local-dev php bin/magento module:disable Magento_TwoFactorAuth
cd ~/misc/apps/test-244-p81-c2-apace.local/.dockerizer/test-244-p81-c2-apace.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes

# Magento 2.4.4 > PHP 8.1 > Composer 2 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c2-nva.local www.test-244-p81-c2-nva.local" \
  --template="magento_2.4.4_nginx_varnish_apache" \
  --required-services="php_8_1_apache,mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p81-c2-nva.local-apache-dev php bin/magento module:disable Magento_TwoFactorAuth
cd ~/misc/apps/test-244-p81-c2-nva.local/.dockerizer/test-244-p81-c2-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes







php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c1.local www.test-244-p81-c1.local" \
  --template="magento_2.4.4_apache" \
  --runner="php_8.1_apache" \
  --required-services="mariadb_10.4_persistent,elasticsearch_7.16.3" \
  --optional-services="redis_6.2" \
  --with-composer_version=1

php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p74-c2.local www.test-244-p74-c2.local" \
  --template="magento_2.4.4_apache" \
  --runner="php_7.4_apache" \
  --required-services="mariadb_10.4_persistent,elasticsearch_7.16.3" \
  --optional-services="redis_6.2"

php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p74-c1.local www.test-244-p74-c1.local" \
  --template="magento_2.4.4_apache" \
  --runner="php_7.4_apache" \
  --required-services="mariadb_10.4_persistent,elasticsearch_7.16.3" \
  --optional-services="redis_6.2" \
  --with-composer_version=1


exit;

### === Test Apache ===
#cd ~/misc/apps/dockerizer_for_php_3/test_56/
#
#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer composition:build-from-template -f \
#  --with-environment='dev' \
#  --template="magento_2.0.2-2.0.18_apache" \
#  --domains='test-apache.local www.test-apache.local' \
#  --runner="php_5.6_apache" \
#  --required-services="mysql_5.7_persistent,mysql_2_5.7_persistent,mysql_3_5.7_persistent" \
#  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
#  --with-web_root="app/"
#
#cd ./.dockerizer/test-apache.local-dev/
#docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml up -d --force-recreate
#cd ~/misc/apps/dockerizer_for_php_3/
#
## === Test Nginx + Varnish + Apache ===
#cd ~/misc/apps/dockerizer_for_php_3/test_56/
#
#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer composition:build-from-template -f \
#  --template="magento_2.1_nginx_varnish_apache" \
#  --domains='test-varnish.local www.test-varnish.local' \
#  --required-services="php_7.0_apache,mysql_5.7_persistent" \
#  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
#  --with-environment='dev' \
#  --with-web_root="app/"
#
#cd ./.dockerizer/test-varnish.local-dev/
#docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml up -d --force-recreate
#cd ~/misc/apps/dockerizer_for_php_3/