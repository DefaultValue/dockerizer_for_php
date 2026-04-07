---
name: generating-magento-templates
description:
    Use when creating, updating, or reviewing Magento 2 Docker composition
    templates for the Dockerizer for PHP project. Covers template YAML
    structure, file naming, version constraints, service codes, and stack
    configurations.
---

# Generating Magento 2 Composition Templates

## Overview

Magento 2 composition templates are YAML files that define which Docker services
(PHP, MySQL, Elasticsearch, etc.) are compatible with specific Magento versions.
Each template targets a version range and a web stack type.

**Template location:**
`templates/vendor/defaultvalue/dockerizer-templates/composition/magento/`

## How to Determine Service Versions

1. **Fetch system requirements** from
   https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements
   — this page covers Adobe Commerce, which has extended support and MORE patch
   versions than Magento Open Source (e.g., Commerce 2.4.4 goes to p17, Open
   Source stops at p13). Requirements for shared patches are identical since
   they share the same codebase.
2. **Verify latest Open Source patch** from repo tags:
   `gh api repos/magento/magento2/git/refs/tags --paginate --jq '.[].ref' | grep 'X.Y.Z'`
3. **Read existing templates** for the previous version as a starting point —
   they're the source of truth for service codes, image tags, and parameter
   patterns.
4. **Compare requirements PATCH BY PATCH** — go through each patch sequentially
   (p8, then p9, then p10...), collecting ALL supported versions for each
   service (MariaDB, Redis, Elasticsearch, Varnish, etc.). Compare each patch
   with the previous one. Create a NEW template whenever ANY service's supported
   versions change.

### Per-Patch Comparison Process

For each patch, check ALL of these against the previous patch:

- **MariaDB** — supported versions may be added or dropped between patches
  (e.g., p8: 10.4 → p9: 10.5+10.4 → p12: 10.6+10.5 → p13: 10.6)
- **Redis** — versions may be added or dropped (e.g., p8: 7.0 → p11: 7.2+7.0 →
  p12: 7.2)
- **Varnish** — version may change (e.g., p8-p11: 7.5 → p12-p13: 7.6)
- **Elasticsearch/OpenSearch** — version may change
- **MySQL** — version may change
- **PHP** — versions may be added or dropped

**Critical:** The system requirements page lists ALL supported versions per
service per patch (e.g., "MariaDB 10.5, 10.4" means both are supported). Each
template must include ALL supported versions as selectable options in the
database/redis/etc. group — not just one.

## Directory Structure & File Naming

```
composition/magento/
├── 2.3.x/          # All 2.3 versions (stable, no new patches)
├── 2.4.0/           # One directory per minor version for 2.4.x
├── 2.4.4/
└── 2.4.8/           # New versions get their own directory
```

**Filename pattern:** `magento_{version}_{stack}[_patch_range].yaml`

| Stack Type                              | Suffix                  |
| --------------------------------------- | ----------------------- |
| Apache (SSL by Traefik)                 | `_apache`               |
| Nginx + Varnish + Apache (SSL by Nginx) | `_nginx_varnish_apache` |

**Patch range suffixes:** `_p0_p2` (range), `_p3` (single), no suffix (entire
minor version). Split only when system requirements change between patches.

## Template YAML Structure

```yaml
app:
    description: "Magento {version_range} ({stack_description})"
    # System requirements: https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements
    supported_packages:
        magento/project-community-edition: ">=X.Y.Z <UPPER_BOUND"
        magento/project-enterprise-edition: ">=X.Y.Z <UPPER_BOUND"
        magento/product-community-edition: ">=X.Y.Z <UPPER_BOUND"
        magento/product-enterprise-edition: ">=X.Y.Z <UPPER_BOUND"
    parameters:
        environment: "prod"
        mysql_root_random_password: ""
        mysql_database: magento_db
        mysql_user: magento_user
        mysql_random_password: ""
    composition:
        required:
            # Web stack + database + search engine
        optional:
            # Redis, RabbitMQ (2.4.7+)
```

**Validation rules:** Exactly ONE `app` root node. All 4 package entries
required with identical version constraint. Constraints use Composer semver.

**Version constraint patterns:**

| Scenario                       | Example                |
| ------------------------------ | ---------------------- |
| Entire minor version           | `>=2.4.7 <2.4.8`       |
| Patch range                    | `>=2.4.4-p3 <2.4.4-p8` |
| Single patch                   | `>=2.4.4-p3 <2.4.4-p4` |
| Last known patch to next minor | `>=2.4.4-p12 <2.4.5`   |

## Stack Configurations

### Apache Stack (direct SSL via Traefik)

```yaml
required:
    apache:
        php_{major}_{minor}_apache:
            service: dv_php_apache # or dv_php_apache_8_3 for PHP 8.3+
            dev_tools:
                - php_apache_development_image
                - mailhog
            parameters:
                composer_version: 2 # Omit for PHP 8.3+ images
                image_version: "X.Y.Z"
                web_root: "pub/"
```

### Nginx + Varnish + Apache Stack

Same as Apache but with nginx/varnish added and `_unsecure` service codes:

```yaml
required:
    nginx:
        nginx_latest:
            service: dv_nginx_proxy_for_varnish
    varnish:
        varnish_{major}_{minor}:
            service: dv_varnish_magento
            parameters:
                # docker-compose service name goes here
                backend_host: php
                # Config path: full_page_cache/default/grace_period
                grace_period: 300
                # Config path: web/secure/offloader_header. Changed to `X-Forwarded-Proto` since Magento 2.1
                ssl_offloaded_header: "X-Forwarded-Proto"
                # Use `/pub/health_check.php` in case the web root equals to the project root
                health_check: "/health_check.php"
                varnish_version: X.Y-alpine
                # Actually used for Nginx service
                varnish_port: 80
    apache:
        php_{major}_{minor}_apache:
            service: dv_php_apache_unsecure # or dv_php_apache_unsecure_8_3 for PHP 8.3+
            # ... same dev_tools and parameters as Apache stack
```

**Key difference:** Apache stack uses `dv_php_apache` (secure), Nginx+Varnish
stack uses `dv_php_apache_unsecure` (SSL terminates at Nginx).

## Service Codes Reference

| Category       | Service Code                 | Use Case                                           |
| -------------- | ---------------------------- | -------------------------------------------------- |
| **PHP/Apache** | `dv_php_apache`              | Apache with SSL (direct Traefik), PHP <=8.2        |
|                | `dv_php_apache_8_3`          | Same but for PHP 8.3+                              |
|                | `dv_php_apache_unsecure`     | Behind Nginx proxy, PHP <=8.2                      |
|                | `dv_php_apache_unsecure_8_3` | Behind Nginx proxy, PHP 8.3+                       |
| **MySQL**      | `dv_mysql_5_6_and_5_7`       | MySQL 5.6/5.7 (2.3.x only)                         |
|                | `dv_mysql_8_0_to_8_4`        | MySQL 8.0+                                         |
| **MariaDB**    | `dv_mariadb_10_1`            | MariaDB 10.1 (2.3.0-2.3.4 only)                    |
|                | `dv_mariadb_10_2_and_above`  | MariaDB 10.2+                                      |
| **Search**     | `dv_elasticsearch`           | All Elasticsearch versions                         |
|                | `magento_opensearch`         | OpenSearch (2.4.6 p0-p4 only, Magento Cloud image) |
|                | `opensearch`                 | OpenSearch (2.4.6-p5+)                             |
| **Cache**      | `dv_redis`                   | All Redis versions                                 |
| **Varnish**    | `dv_varnish_magento`         | Varnish 6.x/7.x                                    |
| **Nginx**      | `dv_nginx_proxy_for_varnish` | Nginx SSL proxy for Varnish stack                  |
| **MQ**         | `dv_rabbitmq`                | RabbitMQ (2.4.7+)                                  |

## Generation Checklist

1. **Check system requirements** and verify latest Open Source patch from repo
   tags
2. **Read existing templates** for the nearest version as a starting point
3. **Compare patch by patch** — for each patch starting from the first, collect
   ALL supported versions for every service. Compare with the previous patch. If
   ANY service differs → new template
4. **Include all supported versions** — each template lists every supported
   version as a selectable option (e.g., if MariaDB supports 10.6 and 10.5, add
   both as `mariadb_10_6_persistent` and `mariadb_10_5_persistent`)
5. **List newest version first** — within each service group, put the
   newest/recommended version first
6. **Create directory** `composition/magento/X.Y.Z/` if needed
7. **Generate both stack types** — Apache and Nginx+Varnish+Apache
8. **Use correct service codes** — `dv_php_apache` vs `_8_3`, `_unsecure` for
   Nginx+Varnish
9. **Set version constraints** — all 4 packages, identical constraint, no
   overlaps
10. **Verify constraints** — `php -r` with `Semver::satisfies()` to confirm no
    gaps or overlaps

## Common Rules

- `composer_version: 2` always. Omit for PHP 8.3+ images.
- `web_root: 'pub/'` always.
- Search engine group name: `elasticsearch` for <=2.4.5, `search_engine` for
  2.4.6+.
- OpenSearch: always attach `opensearch_dashboards` dev tool, match dashboard
  version to OpenSearch version.
- Varnish port: always `80` for modern Varnish (6.x+).
- RabbitMQ global parameters (`rabbitmq_username`, `rabbitmq_password`,
  `rabbitmq_vhost`) only for 2.4.7+.
