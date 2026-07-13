# FederationLib

FederationLib is a complete Federated Database Server/Client implementation in PHP to serve/implement a protection layer
against spam and abusive users on the internet across multiple platforms and use cases.

(This documentation is incomplete)

## Features
 
 - Full implementation of the Federated Database standard for both the client-side and server-side implementation. (Batteries included!)
 - Full Bayesian classification support (Powered by [BayesianServer](https://github.com/nosial/BayesianServer))
 - Automatic report generation - The web service can automatically generate reports based off high-risk score content
   scan submissions by users/operators
 - Extremely configurable for different needs


## Table of Contents

<!-- TOC -->
* [FederationLib](#federationlib)
  * [Features](#features)
  * [Table of Contents](#table-of-contents)
  * [Building & Installing](#building--installing)
    * [Library Usage](#library-usage)
    * [Server Usage](#server-usage)
  * [Configuration](#configuration)
    * [Server configuration](#server-configuration)
    * [Scanning Configuration](#scanning-configuration)
    * [Bayesian Server Configuration](#bayesian-server-configuration)
    * [Database Configuration](#database-configuration)
    * [Redis/Caching configuration](#rediscaching-configuration)
    * [Maintenance Configuration](#maintenance-configuration)
* [License](#license)
<!-- TOC -->


## Building & Installing

There are two approaches to building and installing FederationLib depending on the purpose it's being used for, this
section will cover both how to use FederationLib as a library for PHP applications or deploy FederationLib as a server.

### Library Usage

FederationLib can be used as a library using [Nosial Code Compiler (ncc)](https://github.com/nosial/ncc) within your
`project.yml` configuration file by adding `net.nosial.federationlib` as a dependency

From the n64 repository
```yaml
dependencies:
  net.nosial.loglib2: nosial/loglib2@n64
```

From the github repository
```yaml
dependencies:
  net.nosial.loglib2: nosial/loglib2@github
```

You can also build the library from the source code, first ensure all the dependencies are available in the environment
before building FederationLib by running

```shell
ncc project install
```

Then you can build and install the binary package

```shell
ncc build --configuration release
ncc install target/release/net.nosial.federation.ncc
```


### Server Usage

FederationLib can be deployed as a web service but requires additional steps, currently the setup is optimized for
docker environments allowing the service to be containerized with all the required services and configurations

The project comes with a populated [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml) file

`Dockerfile` is responsible for creating the FederationLib image, the image uses `ghcr.io/nosial/ncc:fpm` as the base
image, in summary the image setups up the following components

 - `nginx`: For handling web requests to php-fpm
 - `supervisord`: For managing services
 - PHP Extensions `redis`, `sockets` and `pdo_mysql`
 - [`LogLib2Server`](https://github.com/nosial/LogLib2Server): To make logging events visible in the docker container
 - [`BayesianServer`](https://github.com/nosial/BayesianServer): Allows support for text classification/learning

The resulting docker image can be deployed using `docker compose`, this container requires a MariaDB database to connect
to and an optional redis or redis-compatible server to also connect to. FederationLib's server can be configured entirely
using environment variables, see the [Configuration](#configuration) section for additional information

Below is an example of how a `docker-compose.yml` file might-look like deploying FederationLib as a web service with
the required services.

```yaml
services:
  federation:
    image: ghcr.io/nosial/federationlib:latest
    container_name: federation
    ports:
      - "7000:7000"
    depends_on:
      mariadb:
        condition: service_healthy
      redis:
        condition: service_healthy
    restart: unless-stopped
    volumes:
      - federation_uploads:/var/www/uploads
      - bayesian_model:/var/www/bayesian_model
    environment:
      # FederationLib Configuration
      - FEDERATION_DATABASE_HOST=mariadb
      - FEDERATION_DATABASE_PORT=3306
      - FEDERATION_DATABASE_USERNAME=${MYSQL_USER:-federation}
      - FEDERATION_DATABASE_PASSWORD=${MYSQL_PASSWORD:-federation}
      - FEDERATION_DATABASE_NAME=${MYSQL_DATABASE:-federation}
      - FEDERATION_REDIS_ENABLED=true
      - FEDERATION_REDIS_HOST=redis
      - FEDERATION_REDIS_PORT=6379
      - FEDERATION_ACCESS_TOKEN=${FEDERATION_ACCESS_TOKEN:-abcdefghijklmnopqrstuvwxyz123456} # CHANGE THIS!!!
    networks:
      - internal_network

  mariadb:
    container_name: federation_mariadb
    image: mariadb:10.5
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-federation_root}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-federation}
      MYSQL_USER: ${MYSQL_USER:-federation}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-federation}
    volumes:
      - mariadb_data:/var/lib/mysql
    networks:
      - internal_network
    expose:
      - "3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "mariadb", "-u", "${MYSQL_USER:-federation}", "-p${MYSQL_PASSWORD:-federation}"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 30s

  redis:
    container_name: federation_redis
    image: redis:alpine
    restart: unless-stopped
    command: redis-server
    volumes:
      - redis_data:/data
    networks:
      - internal_network
    expose:
      - "6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 5s

volumes:
  federation_uploads:
    driver: local
  bayesian_model:
    driver: local
  mariadb_data:
    driver: local
  redis_data:
    driver: local

networks:
  internal_network:
    driver: bridge
    name: federation_network
```

The docker image is configured to store important files in the following paths

 - `/var/www/uploads`: The directory where all file uploads will be stored
 - `/var/www/bayesian_model`: The directory where the Bayesian model will be stored at
 - `/var/www/archives`: The directory where no longer used records are archived at

If everything is configured correctly, docker's entrypoint is designed to execute `federationlib init` before starting
its services to ensure that the database is populated and contains the up-to-date schema structure. This process also
initializes the default operators and fixes any potential misconfiguration issues that can be fixed during this stage.

## Configuration

FederationLib is extremely customizable with many different configuration options to alter, this allows deploying a
Federated Database to be fine-tuned for different services/applications. Configuration management is handled by
[ConfigLib](https://github.com/nosial/ConfigLib), configuration values can be alterted and commited to using the
`configlib` command-line utility, modifying the configuration file or setting environment variables.


### Server configuration

This section is used to configured how the server operates, dictating the permissions that is permitted for public use,
where data is stored at, what limits should be applied.

| Name                                | Environment Variable                                                | Type     | Default Value                                                                                                                                                                                 | Required | Description                                          |
|-------------------------------------|---------------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|------------------------------------------------------|
| `server.base_url`                   | `FEDERATION_BASE_URL`                                               | string   | `http://127.0.0.1:7000`                                                                                                                                                                       | Yes      | Base URL of the server                               |
| `server.name`                       | `FEDERATION_NAME`                                                   | string   | `Federation Server`                                                                                                                                                                           | Yes      | Server display name                                  |
| `server.access_token`               | `FEDERATION_ACCESS_TOKEN`                                           | string   | Randomly generated                                                                                                                                                                            | Yes      | Master access token for authentication               |
| `server.max_upload_size`            | `FEDERATION_MAX_UPLOAD_SIZE`                                        | int      | `52428800` (50 MB)                                                                                                                                                                            | Yes      | Maximum allowed upload size in bytes                 |
| `server.storage_path`               | `FEDERATION_STORAGE_PATH`                                           | string   | `/var/www/uploads`                                                                                                                                                                            | Yes      | Directory where uploaded files are stored            |
| `server.list_audit_logs_max_items`  | `FEDERATION_LIST_AUDIT_LOGS_MAX_ITEMS`                              | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing audit logs       |
| `server.list_entities_max_items`    | `FEDERATION_LIST_ENTITIES_MAX_ITEMS`                                | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing entities         |
| `server.list_operators_max_items`   | `FEDERATION_LIST_OPERATORS_MAX_ITEMS`                               | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing operators        |
| `server.list_evidence_max_items`    | `FEDERATION_LIST_EVIDENCE_MAX_ITEMS`                                | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing evidence         |
| `server.list_blacklist_max_items`   | `FEDERATION_LIST_BLACKLIST_MAX_ITEMS`                               | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing blacklist        |
| `server.list_attachments_max_items` | —                                                                   | int      | `100`                                                                                                                                                                                         | No       | Maximum items returned when listing file attachments |
| `server.list_reports_max_items`     | `FEDERATION_LIST_REPORTS_MAX_ITEMS`                                 | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing reports          |
| `server.public_audit_logs`          | `FEDERATION_PUBLIC_AUDIT_LOGS`                                      | bool     | `true`                                                                                                                                                                                        | Yes      | Whether audit logs are publicly accessible           |
| `server.public_audit_entries`       | —                                                                   | string[] | `["operator_created", "operator_deleted", "attachment_uploaded", "attachment_deleted", "evidence_submitted", "evidence_deleted", "entity_blacklisted", "entity_updated", "report_generated"]` | Yes      | List of audit log types publicly accessible          |
| `server.public_evidence`            | `FEDERATION_PUBLIC_EVIDENCE`                                        | bool     | `true`                                                                                                                                                                                        | Yes      | Whether evidence records are publicly accessible     |
| `server.public_blacklist`           | `FEDERATION_PUBLIC_BLACKLIST`                                       | bool     | `true`                                                                                                                                                                                        | Yes      | Whether blacklist records are publicly accessible    |
| `server.public_entities`            | `FEDERATION_PUBLICdictaing the default public permissions_ENTITIES` | bool     | `true`                                                                                                                                                                                        | Yes      | Whether entity records are publicly accessible       |
| `server.public_reports`             | `FEDERATION_PUBLIC_REPORTS`                                         | bool     | `true`                                                                                                                                                                                        | Yes      | Whether reports are publicly accessible              |
| `server.public_scan_content`        | `FEDERATION_PUBLIC_SCAN_CONTENT`                                    | bool     | `true`                                                                                                                                                                                        | Yes      | Whether scan content endpoint is publicly accessible |
| `server.min_blacklist_time`         | `FEDERATION_MIN_BLACKLIST_TIME`                                     | int      | `1800` (30 min)                                                                                                                                                                               | Yes      | Minimum allowed blacklist expiration time in seconds |


### Scanning Configuration

Scanning configuration section changes the scanning behavior when invoking the request path `/scan` to scan content.

| Name                                            | Environment Variable                                         | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                 |
|-------------------------------------------------|--------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------------------------------------------|
| `scanning.default_rosl_score`                   | `FEDERATION_SCORING_RISK_DEFAULT`                            | float    | `0.0`                                                                                                                                                                                         | Yes      | Default risk score for scanned content                      |
| `scanning.risk_score_steepness`                 | `FEDERATION_SCANNING_RISK_AUTHOR_WHITELISTEDSCORE_STEEPNESS` | float    | `0.25`                                                                                                                                                                                        | Yes      | Steepness of the trust score curve                          |
| `scanning.reputation_update_interval`           | `FEDERATION_SCANNING_REPUTATION_UPDATE_INTERVAL`             | int      | `900` (15 min)                                                                                                                                                                                | Yes      | Interval between reputation updates in seconds              |
| `scanning.good_reputation_threshold`            | `FEDERATION_SCANNING_GOOD_REPUTATION_THRESHOLD`              | int      | `50`                                                                                                                                                                                          | Yes      | Threshold above which reputation is considered good         |
| `scanning.bad_reputation_threshold`             | `FEDERATION_SCANNING_BAD_REPUTATION_THRESHOLD`               | int      | `-50`                                                                                                                                                                                         | Yes      | Threshold below which reputation is considered bad          |
| `scanning.author_blacklisted`                   | `FEDERATION_SCANNING_AUTHOR_BLACKLISTED`                     | float    | `-20.0`                                                                                                                                                                                       | Yes      | Score modifier when author is blacklisted                   |
| `scanning.author_permanently_blacklisted`       | `FEDERATION_SCANNING_AUTHOR_PERMANENTLY_BLACKLISTED`         | float    | `-35.0`                                                                                                                                                                                       | Yes      | Score modifier when author is permanently blacklisted       |
| `scanning.author_whitelisted`                   | `FEDERATION_SCANNING_AUTHOR_WHITELISTED`                     | float    | `20.0`                                                                                                                                                                                        | Yes      | Score modifier when author is whitelisted                   |
| `scanning.named_entity_blacklisted`             | `FEDERATION_SCANNING_NAMED_ENTITY_BLACKLISTED`               | float    | `-8.0`                                                                                                                                                                                        | Yes      | Score modifier when named entity is blacklisted             |
| `scanning.named_entity_permanently_blacklisted` | `FEDERATION_SCANNING_NAMED_ENTITY_PERMANENTLY_BLACKLISTED`   | float    | `-13.0`                                                                                                                                                                                       | Yes      | Score modifier when named entity is permanently blacklisted |
| `scanning.named_entity_whitelisted`             | `FEDERATION_SCANNING_NAMED_ENTITY_WHITELISTED`               | float    | `8.0`                                                                                                                                                                                         | Yes      | Score modifier when named entity is whitelisted             |
| `scanning.author_good_reputation`               | `FEDERATION_SCANNING_AUTHOR_GOOD_REPUTATION`                 | float    | `1.5`                                                                                                                                                                                         | Yes      | Score modifier when author has good reputation              |
| `scanning.author_bad_reputation`                | `FEDERATION_SCANNING_AUTHOR_BAD_REPUTATION`                  | float    | `-2.5`                                                                                                                                                                                        | Yes      | Score modifier when author has bad reputation               |
| `scanning.named_entity_good_repuation`          | `FEDERATION_SCANNING_NAMED_ENTITY_GOOD_REPUTATION`           | float    | `0.8`                                                                                                                                                                                         | Yes      | Score modifier when named entity has good reputation        |
| `scanning.named_entity_bad_repuation`           | `FEDERATION_SCANNING_NAMED_ENTITY_BAD_REPUTATION`            | float    | `-1.8`                                                                                                                                                                                        | Yes      | Score modifier when named entity has bad reputation         |
| `scanning.classification_normal`                | `FEDERATION_SCANNING_CLASSIFICATION_NORMAL`                  | float    | `0.3`                                                                                                                                                                                         | Yes      | Score modifier when content is classified as normal         |
| `scanning.classification_suspicious`            | `FEDERATION_SCANNING_CLASSIFICATION_SUSPICIOUS`              | float    | `-0.3`                                                                                                                                                                                        | Yes      | Score modifier when content is classified as suspicious     |
| `scanning.classification_malicious`             | `FEDERATION_SCANNING_CLASSIFICATION_MALICIOUS`               | float    | `-0.4`                                                                                                                                                                                        | Yes      | Score modifier when content is classified as malicious      |
| `scanning.auto_report`                          | `FEDERATION_SCANNING_AUTO_REPORT`                            | bool     | `true`                                                                                                                                                                                        | Yes      | Whether auto-reporting is enabled                           |
| `scanning.auto_report_threshold`                | `FEDERATION_SCANNING_AUTO_REPORT_THRESHOLD`                  | float    | `30.0`                                                                                                                                                                                        | Yes      | Risk score threshold triggering auto-report                 |
| `scanning.reputation_window_duration`           | `FEDERATION_SCANNING_REPUTATION_WINDOW_DURATION`             | int      | `300` (5 min)                                                                                                                                                                                 | Yes      | Duration of the reputation window in seconds                |
| `scanning.reputation_max_delta`                 | `FEDERATION_SCANNING_REPUTATION_MAX_DELTA`                   | int      | `10`                                                                                                                                                                                          | Yes      | Maximum reputation change per update                        |
| `scanning.reputation_min_delta`                 | `FEDERATION_SCANNING_REPUTATION_MIN_DELTA`                   | int      | `-10`                                                                                                                                                                                         | Yes      | Minimum reputation change per update                        |
| `scanning.reputation_scaling_factor`            | `FEDERATION_SCANNING_REPUTATION_SCALING_FACTOR`              | float    | `0.25`                                                                                                                                                                                        | Yes      | Scaling factor applied to reputation changes                |
| `scanning.reputation_min_bound`                 | —                                                            | int      | `-1000`                                                                                                                                                                                       | No       | Minimum bound for reputation score                          |
| `scanning.reputation_max_bound`                 | —                                                            | int      | `1000`                                                                                                                                                                                        | No       | Maximum bound for reputation score                          |
| `scanning.risk_score_neutral_point`             | —                                                            | float    | `50.0`                                                                                                                                                                                        | No       | Neutral point of the risk score curve                       |
| `scanning.risk_score_scaling_factor`            | —                                                            | float    | `2.3`                                                                                                                                                                                         | No       | Scaling factor for risk score calculation                   |
| `scanning.risk_score_min_bound`                 | —                                                            | float    | `0.0`                                                                                                                                                                                         | No       | Minimum bound for risk score                                |
| `scanning.risk_score_max_bound`                 | —                                                            | float    | `100.0`                                                                                                                                                                                       | No       | Maximum bound for risk score                                |


### Bayesian Server Configuration

This configuration section is responsible for configuring the connection to [`BayesianServer`](https://github.com/nosial/BayesianServer)
so that FederationLib can push training documents and classify unknown documents against the server.

| Name                                            | Environment Variable                                         | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                 |
|-------------------------------------------------|--------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------------------------------------------|
| `bayesian.enabled`                              | `FEDERATION_BS_ENABLED`                                      | bool     | `true`                                                                                                                                                                                        | Yes      | Whether Bayesian filtering is enabled                       |
| `bayesian.ssl`                                  | `FEDERATION_BS_SSL`                                          | bool     | `false`                                                                                                                                                                                       | Yes      | Whether to use SSL for BayesianServer connection            |
| `bayesian.host`                                 | `FEDERATION_BS_HOST`                                         | string   | `127.0.0.1`                                                                                                                                                                                   | Yes      | BayesianServer host address                                 |
| `bayesian.port`                                 | `FEDERATION_BS_PORT`                                         | int      | `6380`                                                                                                                                                                                        | Yes      | BayesianServer port                                         |
| `bayesian.classify_known_tokens`                | `FEDERATION_BS_CLASSIFY_KNOWN_TOKENS`                        | bool     | `true`                                                                                                                                                                                        | Yes      | Only classify when majority of tokens are known             |


### Database Configuration

This configuration section is responsible for configuring the database connection to MariaDB

| Name                                            | Environment Variable                                         | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                 |
|-------------------------------------------------|--------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------------------------------------------|
| `database.host`                                 | `FEDERATION_DATABASE_HOST`                                   | string   | `127.0.0.1`                                                                                                                                                                                   | Yes      | Database server host                                        |
| `database.port`                                 | `FEDERATION_DATABASE_PORT`                                   | int      | `3306`                                                                                                                                                                                        | Yes      | Database server port                                        |
| `database.username`                             | `FEDERATION_DATABASE_USERNAME`                               | string   | `root`                                                                                                                                                                                        | Yes      | Database username                                           |
| `database.password`                             | `FEDERATION_DATABASE_PASSWORD`                               | string   | `root`                                                                                                                                                                                        | Yes      | Database password                                           |
| `database.name`                                 | `FEDERATION_DATABASE_NAME`                                   | string   | `federation`                                                                                                                                                                                  | Yes      | Database name                                               |
| `database.charset`                              | `FEDERATION_DATABASE_CHARSET`                                | string   | `utf8mb4`                                                                                                                                                                                     | Yes      | Database connection charset                                 |
| `database.collation`                            | `FEDERATION_DATABASE_COLLATION`                              | string   | `utf8mb4_unicode_ci`                                                                                                                                                                          | Yes      | Database collation                                          |

### Redis/Caching configuration

This configuration section is responsible for configuring the redis connection and object caching to improve server-side
response performance

| Name                                            | Environment Variable                                         | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                 |
|-------------------------------------------------|--------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------------------------------------------|
| `redis.enabled`                                 | `FEDERATION_REDIS_ENABLED`                                   | bool     | `false`                                                                                                                                                                                       | Yes      | Whether Redis caching is enabled                            |
| `redis.host`                                    | `FEDERATION_REDIS_HOST`                                      | string   | `127.0.0.1`                                                                                                                                                                                   | Yes      | Redis server host                                           |
| `redis.port`                                    | `FEDERATION_REDIS_PORT`                                      | int      | `6379`                                                                                                                                                                                        | Yes      | Redis server port                                           |
| `redis.password`                                | `FEDERATION_REDIS_PASSWORD`                                  | string   | `null`                                                                                                                                                                                        | Yes      | Redis password (null for no auth)                           |
| `redis.database`                                | `FEDERATION_REDIS_DATABASE`                                  | int      | `0`                                                                                                                                                                                           | Yes      | Redis database index                                        |
| `redis.throw_on_errors`                         | `FEDERATION_CACHE_THROW_ON_ERRORS`                           | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to throw exceptions on Redis errors                 |
| `redis.pre_cache_enabled`                       | `FEDERATION_PRE_CACHE_ENABLED`                               | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to pre-cache objects before retrieval               |
| `redis.system_caching_enabled`                  | `FEDERATION_SYSTEM_CACHING_ENABLED`                          | bool     | `true`                                                                                                                                                                                        | Yes      | Whether system-level objects are cached                     |
| `redis.operator_cache_enabled`                  | `FEDERATION_OPERATOR_CACHE_ENABLED`                          | bool     | `true`                                                                                                                                                                                        | Yes      | Whether operator cache is enabled                           |
| `redis.operator_cache_limit`                    | `FEDERATION_OPERATOR_CACHE_LIMIT`                            | int      | `1000`                                                                                                                                                                                        | Yes      | Maximum number of operators to cache                        |
| `redis.operator_cache_ttl`                      | `FEDERATION_OPERATOR_CACHE_TTL`                              | int      | `600` (10 min)                                                                                                                                                                                | Yes      | TTL for operator cache entries in seconds                   |
| `redis.entity_cache_enabled`                    | `FEDERATION_ENTITY_CACHE_ENABLED`                            | bool     | `true`                                                                                                                                                                                        | Yes      | Whether entity cache is enabled                             |
| `redis.entity_cache_limit`                      | `FEDERATION_ENTITY_CACHE_LIMIT`                              | int      | `5000`                                                                                                                                                                                        | Yes      | Maximum number of entities to cache                         |
| `redis.entity_cache_ttl`                        | `FEDERATION_ENTITY_CACHE_TTL`                                | int      | `600` (10 min)                                                                                                                                                                                | Yes      | TTL for entity cache entries in seconds                     |
| `redis.file_attachment_cache_enabled`           | `FEDERATION_FILE_ATTACHMENT_CACHE_ENABLED`                   | bool     | `true`                                                                                                                                                                                        | Yes      | Whether file attachment cache is enabled                    |
| `redis.file_attachment_cache_limit`             | `FEDERATION_FILE_ATTACHMENT_CACHE_LIMIT`                     | int      | `2000`                                                                                                                                                                                        | Yes      | Maximum number of file attachments to cache                 |
| `redis.file_attachment_cache_ttl`               | `FEDERATION_FILE_ATTACHMENT_CACHE_TTL`                       | int      | `600` (10 min)                                                                                                                                                                                | Yes      | TTL for file attachment cache entries in seconds            |
| `redis.evidence_cache_enabled`                  | `FEDERATION_EVIDENCE_CACHE_ENABLED`                          | bool     | `true`                                                                                                                                                                                        | Yes      | Whether evidence cache is enabled                           |
| `redis.evidence_cache_limit`                    | `FEDERATION_EVIDENCE_CACHE_LIMIT`                            | int      | `3000`                                                                                                                                                                                        | Yes      | Maximum number of evidence records to cache                 |
| `redis.evidence_cache_ttl`                      | `FEDERATION_EVIDENCE_CACHE_TTL`                              | int      | `600` (10 min)                                                                                                                                                                                | Yes      | TTL for evidence cache entries in seconds                   |
| `redis.report_cache_enabled`                    | `FEDERATION_REPORT_CACHE_ENABLED`                            | bool     | `true`                                                                                                                                                                                        | Yes      | Whether report cache is enabled                             |
| `redis.report_cache_limit`                      | `FEDERATION_REPORT_CACHE_LIMIT`                              | int      | `1000`                                                                                                                                                                                        | Yes      | Maximum number of reports to cache                          |
| `redis.report_cache_ttl`                        | `FEDERATION_REPORT_CACHE_TTL`                                | int      | `600` (10 min)                                                                                                                                                                                | Yes      | TTL for report cache entries in seconds                     |
| `redis.audit_log_cache_enabled`                 | —                                                            | bool     | `true`                                                                                                                                                                                        | No       | Whether audit log cache is enabled                          |
| `redis.audit_log_cache_limit`                   | —                                                            | int      | `1000`                                                                                                                                                                                        | No       | Maximum number of audit log records to cache                |
| `redis.audit_log_cache_ttl`                     | —                                                            | int      | `600` (10 min)                                                                                                                                                                                | No       | TTL for audit log cache entries in seconds                  |
| `redis.blacklist_cache_enabled`                 | —                                                            | bool     | `true`                                                                                                                                                                                        | No       | Whether blacklist cache is enabled                          |
| `redis.blacklist_cache_limit`                   | —                                                            | int      | `3000`                                                                                                                                                                                        | No       | Maximum number of blacklist records to cache                |
| `redis.blacklist_cache_ttl`                     | —                                                            | int      | `600` (10 min)                                                                                                                                                                                | No       | TTL for blacklist cache entries in seconds                  |

### Maintenance Configuration

This configuration is responsible for configuring how the maintenance command operates, dictating how long database
records should be retained before they are considered eligible for cleanup

| Name                                            | Environment Variable                                         | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                 |
|-------------------------------------------------|--------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------------------------------------------|
| `maintenance.enabled`                           | `FEDERATION_MAINTENANCE_ENABLED`                             | bool     | `true`                                                                                                                                                                                        | Yes      | Whether maintenance tasks are enabled                       |
| `maintenance.clean_audit_logs`                  | `FEDERATION_MAINTENANCE_CLEAN_AUDIT_LOGS`                    | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to clean expired audit logs                         |
| `maintenance.clean_audit_logs_ttl`              | `FEDERATION_MAINTENANCE_CLEAN_AUDIT_LOGS_TTL`                | int      | `63072000` (2 years)                                                                                                                                                                          | Yes      | TTL for audit logs before cleanup in seconds                |
| `maintenance.clean_blacklist`                   | `FEDERATION_MAINTENANCE_CLEAN_BLACKLIST`                     | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to clean expired blacklist records                  |
| `maintenance.clean_blacklist_ttl`               | `FEDERATION_MAINTENANCE_CLEAN_BLACKLIST_TTL`                 | int      | `31536000` (1 year)                                                                                                                                                                           | Yes      | TTL for blacklist records before cleanup in seconds         |
| `maintenance.clean_evidence`                    | `FEDERATION_MAINTENANCE_CLEAN_EVIDENCE`                      | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to clean expired evidence records                   |
| `maintenance.clean_evidence_ttl`                | `FEDERATION_MAINTENANCE_CLEAN_EVIDENCE_TTL`                  | int      | `63072000` (2 years)                                                                                                                                                                          | Yes      | TTL for evidence records before cleanup in seconds          |
| `maintenance.clean_reports`                     | `FEDERATION_MAINTENANCE_CLEAN_REPORTS`                       | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to clean expired reports                            |
| `maintenance.clean_reports_ttl`                 | `FEDERATION_MAINTENANCE_CLEAN_REPORTS_TTL`                   | int      | `63072000` (2 years)                                                                                                                                                                          | Yes      | TTL for reports before cleanup in seconds                   |
| `maintenance.clean_file_attachments`            | `FEDERATION_MAINTENANCE_CLEAN_FILE_ATTACHMENTS`              | bool     | `true`                                                                                                                                                                                        | Yes      | Whether to clean expired file attachments                   |
| `maintenance.clean_file_attachments_ttl`        | `FEDERATION_MAINTENANCE_CLEAN_FILE_ATTACHMENTS_TTL`          | int      | `63072000` (2 years)                                                                                                                                                                          | Yes      | TTL for file attachments before cleanup in seconds          |
| `maintenance.clean_entities`                    | `FEDERATION_MAINTENANCE_CLEAN_ENTITIES`                      | bool     | `false`                                                                                                                                                                                       | Yes      | Whether to clean expired entity records                     |
| `maintenance.clean_entities_ttl`                | `FEDERATION_MAINTENANCE_CLEAN_ENTITIES_TTL`                  | int      | `63072000` (2 years)                                                                                                                                                                          | Yes      | TTL for entity records before cleanup in seconds            |



# License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.