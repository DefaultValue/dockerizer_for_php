#!/bin/bash
set -e

cd ./test_56/

php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer composition:build-from-template -f \
  --template="magento_2.0.2-2.0.x" \
  --domains='test.local www.test.local' \
  --runner="php_5.6_apache" \
  --required-services="mysql_5.7_persistent,mysql_2_5.7_persistent,mysql_3_5.7_persistent" \
  --optional-services="redis_5.0,elasticsearch_6.8.11_persistent" \
  --with-environment='dev' \
  --with-test_3='Test 3 lorem ipsum' \
  --with-web_root="app/"
#  --required-services="mysql_5.6_persistent,mysql_5.7_persistent,mysql_2_5.7_persistent,mysql_3_5.7_persistent" \
#  --optional-services="redis_5.0,redis_2,redis_3,elasticsearch_6.8.11_persistent" \

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer composition:build-from-template \
#  --domains='google.com www.google.com'

cd ./.dockerizer/test.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml up -d --force-recreate

cd ~/misc/apps/dockerizer_for_php_3/