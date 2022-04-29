#!/bin/bash

set -e

# ===== Magneto 2.0 =====

#php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.0.2 -f \
#  --template="magento_2.0.2-2.0.18_apache" \
#  --runner="php_5.6_apache" \
#  --required-services="mariadb_10.1_persistent" \
#  --optional-services="redis_5.0" \
#  --domains='test-apache.local www.test-apache.local'


# ===== Magneto 2.1 =====

# Magento 2.1.18 > PHP 7.0 > Composer 1 > Apache
# @TODO: still asking for optional services in the non-interactive mode!
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -n -f \
  --domains="test-2118-p70-apache.local www.test-2118-p70-apache.local" \
  --template="magento_2.1_apache" \
  --runner="php_7_0_apache" \
  --required-services="mysql_5_7_persistent" \
  --optional-services="redis_5_0"
docker exec -it test-2118-p70-apache.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-2118-p70-apache.local/.dockerizer/test-2118-p70-apache.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-2118-p70-apache.local/

# Magento 2.1.18 > PHP 7.0 > Composer 1 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.1.18 -n -f \
  --domains="test-2118-p70-nva.local www.test-2118-p70-nva.local" \
  --template="magento_2.1_nginx_varnish_apache" \
  --required-services="php_7_0_apache,mysql_5_7_persistent" \
  --optional-services="redis_5_0"
docker exec -it test-2118-p70-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-2118-p70-nva.local/.dockerizer/test-2118-p70-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-2118-p70-nva.local/


# ===== Magneto 2.2 =====

# Magento 2.2.1 > PHP 7.0 > Composer 1 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.2.1 -f \
  --domains="test-221-p70-apache.local www.test-221-p70-apache.local" \
  --template="magento_2.2_apache" \
  --runner="php_7_0_apache" \
  --required-services="mysql_5_6_persistent" \
  --optional-services="redis_5_0"
docker exec -it test-221-p70-apache.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-221-p70-apache.local/.dockerizer/test-221-p70-apache.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-221-p70-apache.local/

# Magento 2.2.1 > PHP 7.1 > Composer 1 > Nginx + Varnish 4 + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.2.11 -f \
  --domains="test-2211-p71-nva.local www.test-2211-p71-nva.local" \
  --template="magento_2.2_nginx_varnish_apache" \
  --required-services="varnish_4,php_7_1_apache,mysql_5_7_persistent" \
  --optional-services="redis_5_0"
docker exec -it test-2211-p71-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-2211-p71-nva.local/.dockerizer/test-2211-p71-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-2211-p71-nva.local/

# No version with Varnish 5 for now


# ===== Magneto 2.3.0 =====

# Magento 2.3.0 > PHP 7.1 > Composer 1 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.3.0 -f \
  --domains="test-230-p71-apache.local www.test-230-p71-apache.local" \
  --template="magento_2.3.0_apache" \
  --runner="php_7_1_apache" \
  --required-services="mysql_5_7_persistent" \
  --optional-services="redis_5_0,elasticsearch_5_6_16"
docker exec -it test-230-p71-apache.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-230-p71-apache.local/.dockerizer/test-230-p71-apache.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-230-p71-apache.local/

# Magento 2.3.0 > PHP 7.2 > Composer 1 > Nginx + Varnish 4 + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.3.0 -f \
  --domains="test-230-p72-nva.local www.test-230-p72-nva.local" \
  --template="magento_2.3.0_nginx_varnish_apache" \
  --required-services="varnish_4,php_7_2_apache,mariadb_10_1_persistent" \
  --optional-services="redis_5_0,elasticsearch_5_6_16_persistent"
docker exec -it test-230-p72-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-230-p72-nva.local/.dockerizer/test-230-p72-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-230-p72-nva.local/


# ===== Magneto 2.3.1 and 2.3.2 =====

# Magento 2.3.1 > PHP 7.1 > Composer 1 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.3.1 -f \
  --domains="test-231-p71-apache.local www.test-231-p71-apache.local" \
  --template="magento_2.3.1-2.3.2_apache" \
  --runner="php_7_1_apache" \
  --required-services="mysql_5_7_persistent" \
  --optional-services="redis_5_0,elasticsearch_6_8_11"
#docker exec -it test-231-p71-apache.local-dev php bin/magento sampledata:deploy
#docker exec -it test-231-p71-apache.local-dev php bin/magento setup:upgrade
docker exec -it test-231-p71-apache.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-231-p71-apache.local/.dockerizer/test-231-p71-apache.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-231-p71-apache.local/

# Magento 2.3.1 > PHP 7.2 > Composer 1 > Nginx + Varnish 4 + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.3.1 -f \
  --domains="test-231-p72-nva.local www.test-231-p72-nva.local" \
  --template="magento_2.3.1-2.3.2_nginx_varnish_apache" \
  --required-services="varnish_4,php_7_2_apache,mariadb_10_1_persistent" \
  --optional-services="redis_5_0,elasticsearch_6_8_11_persistent"
#docker exec -it test-231-p72-nva.local-apache-dev php bin/magento sampledata:deploy
#docker exec -it test-231-p72-nva.local-apache-dev php bin/magento setup:upgrade
docker exec -it test-231-p72-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-231-p72-nva.local/.dockerizer/test-231-p72-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-231-p72-nva.local/

# Magento 2.3.2 > PHP 7.2 > MariaDB 10.2 > Elasticsearch 5.6.16 > Composer 1 > Nginx + Varnish 4 + Apache
# Yep, domain is the same as above
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.3.2 -f \
  --domains="test-232-p72-nva.local www.test-232-p72-nva.local" \
  --template="magento_2.3.1-2.3.2_nginx_varnish_apache" \
  --required-services="varnish_4,php_7_2_apache,mariadb_10_2_persistent" \
  --optional-services="redis_5_0,elasticsearch_5_6_16"
#docker exec -it test-232-p72-nva.local-apache-dev php bin/magento sampledata:deploy
#docker exec -it test-232-p72-nva.local-apache-dev php bin/magento setup:upgrade
docker exec -it test-232-p72-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-232-p72-nva.local/.dockerizer/test-232-p72-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-232-p72-nva.local/

# ===== Magneto 2.3.3 =====







# ===== Magneto 2.4.4 =====

# Magento 2.4.4 > PHP 8.1 > Composer 2 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c2-apace.local www.test-244-p81-c2-apache.local" \
  --template="magento_2.4.4_apache" \
  --runner="php_8_1_apache" \
  --required-services="mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p81-c2-apace.local-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p81-c2-apace.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p81-c2-apace.local/.dockerizer/test-244-p81-c2-apace.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p81-c2-apace.local/

# Magento 2.4.4 > PHP 8.1 > Composer 2 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c2-nva.local www.test-244-p81-c2-nva.local" \
  --template="magento_2.4.4_nginx_varnish_apache" \
  --required-services="php_8_1_apache,mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p81-c2-nva.local-apache-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p81-c2-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p81-c2-nva.local/.dockerizer/test-244-p81-c2-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p81-c2-nva.local/

# Magento 2.4.4 > PHP 8.1 > Composer 1 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p81-c1-nva.local www.test-244-p81-c1-nva.local" \
  --template="magento_2.4.4_nginx_varnish_apache" \
  --required-services="php_8_1_apache,mysql_8_0_persistent,elasticsearch_7_16_3_persistent" \
  --with-composer_version=1 \
  --optional-services="redis_6_2"
docker exec -it test-244-p81-c1-nva.local-apache-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p81-c1-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p81-c1-nva.local/.dockerizer/test-244-p81-c1-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p81-c1-nva.local/

# Magento 2.4.4 > PHP 7.4 > Composer 2 > Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p74-c2-apace.local www.test-244-p74-c2-apache.local" \
  --template="magento_2.4.4_apache" \
  --runner="php_7_4_apache" \
  --required-services="mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p74-c2-apace.local-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p74-c2-apace.local-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p74-c2-apace.local/.dockerizer/test-244-p74-c2-apace.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p74-c2-apace.local/

# Magento 2.4.4 > PHP 7.4 > Composer 2 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p74-c2-nva.local www.test-244-p74-c2-nva.local" \
  --template="magento_2.4.4_nginx_varnish_apache" \
  --required-services="php_7_4_apache,mariadb_10_4_persistent,elasticsearch_7_16_3" \
  --optional-services="redis_6_2"
docker exec -it test-244-p74-c2-nva.local-apache-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p74-c2-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p74-c2-nva.local/.dockerizer/test-244-p74-c2-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p74-c2-nva.local/

# Magento 2.4.4 > PHP 7.4 > Composer 1 > Nginx + Varnish + Apache
php ~/misc/apps/dockerizer_for_php_3/bin/dockerizer magento:setup 2.4.4 -f \
  --domains="test-244-p74-c1-nva.local www.test-244-p74-c1-nva.local" \
  --template="magento_2.4.4_nginx_varnish_apache" \
  --required-services="php_7_4_apache,mysql_8_0_persistent,elasticsearch_7_16_3_persistent" \
  --with-composer_version=1 \
  --optional-services="redis_6_2"
docker exec -it test-244-p74-c1-nva.local-apache-dev php bin/magento module:disable Magento_TwoFactorAuth
docker exec -it test-244-p74-c1-nva.local-apache-dev php bin/magento indexer:reindex
cd ~/misc/apps/test-244-p74-c1-nva.local/.dockerizer/test-244-p74-c1-nva.local-dev/
docker-compose -f docker-compose.yaml -f docker-compose-dev-tools.yaml down --volumes
cd ~ ; rm -rf ~/misc/apps/test-244-p74-c1-nva.local/
