#!/bin/bash
set -e

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.0.18 -f \
#  --template="magento_2.0.2-2.0.x_apache" \
#  --runner="php_5.6_apache" \
#  --required-services="mysql_5.7_persistent,mysql_2_5.7_persistent,mysql_3_5.7_persistent" \
#  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
#  --domains='test-apache.local www.test-apache.local'

php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -f \
  --template="magento_2.1_nginx_varnish_apache" \
  --required-services="php_7.0_apache,mysql_5.7_persistent" \
  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
  --domains='test-apache.local www.test-apache.local'

exit;

### === Test Apache ===
#cd ~/misc/apps/dockerizer_for_php_3/test_56/
#
#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer composition:build-from-template -f \
#  --with-environment='dev' \
#  --template="magento_2.0.2-2.0.x_apache" \
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