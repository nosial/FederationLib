# FederationLib

FederationLib is a complete Federated Database Server/Client implementation in PHP to serve/implement a protection layer
against spam and abusive users on the internet across multiple platforms and use cases.

The implementation is based of the [Open Federated Database](https://github.com/Nosial/OFD-Specification) specification

## Features
 
 - Full implementation of the Federated Database standard for both the client-side and server-side implementation. (Batteries included!)
 - Extensible with [plugins](PLUGINS.md), eg; Bayesian content classification with BayesianPlugin (Powered by
   [BayesianServer](https://github.com/nosial/BayesianServer))
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
      * [Client Usage](#client-usage)
    * [Server Usage](#server-usage)
    * [Locally testing/development](#locally-testingdevelopment)
    * [Command-Line Interface](#command-line-interface)
  * [Configuration](#configuration)
    * [Server configuration](#server-configuration)
    * [Scanning Configuration](#scanning-configuration)
    * [Database Configuration](#database-configuration)
    * [Redis/Caching configuration](#rediscaching-configuration)
    * [Search Configuration](#search-configuration)
    * [Maintenance Configuration](#maintenance-configuration)
    * [Plugins Configuration](#plugins-configuration)
    * [Bayesian Model Configuration](#bayesian-model-configuration)
  * [Plugins](#plugins)
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
  net.nosial.federation: nosial/federationlib@n64
```

From the github repository
```yaml
dependencies:
  net.nosial.federation: nosial/federationlib@github
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

#### Client Usage

Once installed, the `FederationClient` class provides the full client-side implementation of the specification, allowing
applications to scan content, query records, submit reports and manage entities against any OFD-compliant server.

```php
use FederationLib\FederationClient;

// Connect to a Federation server, optionally with an operator access token
$client = new FederationClient('https://federation.example.com', 'operator-access-token');

// Scan content for risk assessment
$result = $client->scanContent(
    new \FederationLib\Objects\ContentInput('Suspicious message to scan'),
    author: 'user@example.com'
);
printf("Risk score: %f\n", $result->getRiskScore());

// Submit a report against an entity
$client->submitReport(
    'user@example.com',
    new \FederationLib\Objects\ContentInput('Spam content'),
    \FederationLib\Enums\IncidentType::SPAM
);

// Search for existing entities
$results = $client->search('user@example.com');
```

The client covers the full server API: operators, entities, evidence, reports, blacklist records, file attachments,
audit logs, search and server information. All methods that modify data require an operator access token with the
appropriate permissions, see the OFD specification for details on the request/response contracts.

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
 - [`BayesianServer`](https://github.com/nosial/BayesianServer): Text classification/learning, used by BayesianPlugin

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
 - `/var/www/bayesian_model`: The directory of BayesianServer's data, which contains:
   - `model/`: The Bayesian model
   - `archive.csv`: The archive of every document the model was trained with
   - `backups/`: The backups of broken models that were replaced by `federationlib init`, one timestamped directory
     per backup
 - `/var/www/archives`: The directory where no longer used records are archived at

Volumes created before the `model/` directory was introduced store the model directly in `/var/www/bayesian_model`,
the entrypoint moves those files into `model/` on the first start.

If everything is configured correctly, docker's entrypoint is designed to execute `federationlib init` before starting
its services to ensure that the database is populated and contains the up-to-date schema structure. This process also
initializes the default operators and fixes any potential misconfiguration issues that can be fixed during this stage.

When BayesianPlugin is enabled (the default), `federationlib init` also checks the Bayesian model before the services
are started, which allows the container to repair the model on its own:

 - BayesianServer is started temporarily with `temporary_start_bayesian.sh`, which starts the server with a dedicated
   supervisord instance (without any of the other services) and only exits once the server is reachable
 - The model is broken if the server does not become reachable (eg; the model can't be loaded), if the server does
   not respond correctly, if the model's statistics are invalid, if the model contains labels that are not
   classification flags or if a classification fails. An empty model is not broken, but it's rebuilt if there are at
   least 20 (see `bayesian_model.minimum_evidence`) classified evidence records with text content to train it with
 - A broken model is rebuilt: the server is stopped, the `model/` directory is moved to `backups/<timestamp>/`, the
   server is started again with a new model, which is then trained with every classified evidence record from the
   oldest to the newest (the same way BayesianPlugin trains the model when evidence is classified) if there are enough
   of them
 - The temporary server is stopped with `stop_temporary_bayesian.sh`, saving the model, before the services
   (including BayesianServer) are started

The container does not start if the model is broken and could not be repaired. The check is skipped if BayesianPlugin
is not enabled or if the scripts are not available (eg; outside the docker image), see
[Bayesian Model Configuration](#bayesian-model-configuration).

The entrypoint also accepts the following docker-only environment variables, processed in this order before
`federationlib init`. The container does not start if either step fails.

| Environment Variable | Description                                                                                                                                                                                  |
|----------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `AUTOSTART`          | The path to a shell script executed on every container start, eg; to install additional services or dependencies required by plugins. Executed directly if executable, otherwise with `bash` |
| `REQUIRE_PLUGINS`    | A comma-separated list of plugin packages to install or update, see [Plugins](#plugins)                                                                                                      |

### Locally testing/development

To locally deploy FederationLib quickly for running tests, you'd want to preform several steps each time you are ready
to deploy. The included [Makefile](Makefile) makes the process easier if you have `docker`, `docker compose`, `ncc` and
`phpunit` in your environment

First run `make` or run `make clean` first if you already ran a build previously, then run `make test-env` to create
the test environment in docker (this uses the test versions of the docker so that the test plugin is included), wait
for the server to become available on port `7000` before running `make test`

The reason for two builds, one locally and one for docker is so that FederationClient can be built and used locally while
a live-server can be deployed to be used to against at.

Test units are designed to test against a live server so that both the client capabilities and server capabilities can
be tested under the same project. Most tests are designed to test against the server's logic and to test if it consists
with specification requirements.

### Command-Line Interface

Deployments that do not use the bundled docker entrypoint can manage the server through the `federationlib` command-line
utility, run `federationlib --help` for usage details and `federationlib --help <command>` for command-specific
documentation.

| Command                                        | Description                                                                                      |
|------------------------------------------------|--------------------------------------------------------------------------------------------------|
| `federationlib init`                           | Initializes FederationLib's database schema and default operators, and checks the Bayesian model |
| `federationlib create-operator`                | Creates a new operator with specified permissions                                                |
| `federationlib edit-operator`                  | Edits an operator's permissions and status                                                       |
| `federationlib get-operator`                   | Retrieves information about a specific operator by UUID                                          |
| `federationlib list-operators`                 | Lists all operators with pagination support                                                      |
| `federationlib delete-operator`                | Deletes an operator by UUID                                                                      |
| `federationlib generate-operator-access-token` | Generates a new access token for an operator                                                     |
| `federationlib list-audit`                     | Lists audit log entries                                                                          |
| `federationlib maintenance`                    | Runs maintenance tasks to clean up expired records                                               |

## Configuration

FederationLib is extremely customizable with many different configuration options to alter, this allows deploying a
Federated Database to be fine-tuned for different services/applications. Configuration management is handled by
[ConfigLib](https://github.com/nosial/ConfigLib), configuration values can be alterted and commited to using the
`configlib` command-line utility, modifying the configuration file or setting environment variables.


### Server configuration

This section is used to configured how the server operates, dictating the permissions that is permitted for public use,
where data is stored at, what limits should be applied.

The production Docker image sets `display_errors = Off` and `display_startup_errors = Off`. PHP errors remain available
to registered handlers such as LogLib2, but are not emitted into HTTP responses.

| Name                                | Environment Variable                   | Type     | Default Value                                                                                                                                                                                 | Required | Description                                                                                                           |
|-------------------------------------|----------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------------------------------------|
| `server.base_url`                   | `FEDERATION_BASE_URL`                  | string   | `http://127.0.0.1:7000`                                                                                                                                                                       | Yes      | Base URL of the server                                                                                                |
| `server.name`                       | `FEDERATION_NAME`                      | string   | `Federation Server`                                                                                                                                                                           | Yes      | Server display name                                                                                                   |
| `server.access_token`               | `FEDERATION_ACCESS_TOKEN`              | string   | Randomly generated                                                                                                                                                                            | Yes      | Master access token for authentication                                                                                |
| `server.max_upload_size`            | `FEDERATION_MAX_UPLOAD_SIZE`           | int      | `52428800` (50 MB)                                                                                                                                                                            | Yes      | Maximum allowed upload size in bytes                                                                                  |
| `server.storage_path`               | `FEDERATION_STORAGE_PATH`              | string   | `/var/www/uploads`                                                                                                                                                                            | Yes      | Directory where uploaded files are stored                                                                             |
| `server.list_audit_logs_max_items`  | `FEDERATION_LIST_AUDIT_LOGS_MAX_ITEMS` | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing audit logs                                                                        |
| `server.list_entities_max_items`    | `FEDERATION_LIST_ENTITIES_MAX_ITEMS`   | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing entities                                                                          |
| `server.list_operators_max_items`   | `FEDERATION_LIST_OPERATORS_MAX_ITEMS`  | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing operators                                                                         |
| `server.list_evidence_max_items`    | `FEDERATION_LIST_EVIDENCE_MAX_ITEMS`   | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing evidence                                                                          |
| `server.list_blacklist_max_items`   | `FEDERATION_LIST_BLACKLIST_MAX_ITEMS`  | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing blacklist                                                                         |
| `server.list_attachments_max_items` | —                                      | int      | `100`                                                                                                                                                                                         | No       | Maximum items returned when listing file attachments                                                                  |
| `server.list_reports_max_items`     | `FEDERATION_LIST_REPORTS_MAX_ITEMS`    | int      | `100`                                                                                                                                                                                         | Yes      | Maximum items returned when listing reports                                                                           |
| `server.public_audit_logs`          | `FEDERATION_PUBLIC_AUDIT_LOGS`         | bool     | `true`                                                                                                                                                                                        | Yes      | Whether audit logs are publicly accessible                                                                            |
| `server.public_audit_entries`       | —                                      | string[] | `["operator_created", "operator_deleted", "attachment_uploaded", "attachment_deleted", "evidence_submitted", "evidence_deleted", "entity_blacklisted", "entity_updated", "report_generated"]` | Yes      | List of audit log types publicly accessible                                                                           |
| `server.public_evidence`            | `FEDERATION_PUBLIC_EVIDENCE`           | bool     | `true`                                                                                                                                                                                        | Yes      | Whether evidence records are publicly accessible                                                                      |
| `server.public_blacklist`           | `FEDERATION_PUBLIC_BLACKLIST`          | bool     | `true`                                                                                                                                                                                        | Yes      | Whether blacklist records are publicly accessible                                                                     |
| `server.public_entities`            | `FEDERATION_PUBLIC_ENTITIES`           | bool     | `true`                                                                                                                                                                                        | Yes      | Whether entity records are publicly accessible                                                                        |
| `server.public_entity_metadata`     | `FEDERATION_PUBLIC_ENTITY_METADATA`    | bool     | `false`                                                                                                                                                                                       | Yes      | Whether entity metadata is publicly accessible to unauthenticated users                                               |
| `server.public_reports`             | `FEDERATION_PUBLIC_REPORTS`            | bool     | `true`                                                                                                                                                                                        | Yes      | Whether reports are publicly accessible                                                                               |
| `server.public_scan_content`        | `FEDERATION_PUBLIC_SCAN_CONTENT`       | bool     | `true`                                                                                                                                                                                        | Yes      | Whether scan content endpoint is publicly accessible                                                                  |
| `server.public_query_entity`        | `FEDERATION_PUBLIC_QUERY_ENTITY`       | bool     | `true`                                                                                                                                                                                        | Yes      | Whether entity relationship queries are publicly accessible                                                           |
| `server.min_blacklist_time`         | `FEDERATION_MIN_BLACKLIST_TIME`        | int      | `1800` (30 min)                                                                                                                                                                               | Yes      | Minimum allowed blacklist expiration time in seconds                                                                  |
| `server.top_threats_limit`          | `FEDERATION_TOP_THREATS_LIMIT`         | int      | `25`                                                                                                                                                                                          | Yes      | Maximum number of top-threat entities to return                                                                       |
| `server.allow_illegal_content`      | `FEDERATION_ALLOW_ILLEGAL_CONTENT`     | bool     | `true`                                                                                                                                                                                        | Yes      | Whether `ILLEGAL_CONTENT` reports are accepted; rejected with 403 if not                                              |
| `server.link_subdomain_entities`    | `FEDERATION_LINK_SUBDOMAIN_ENTITIES`   | bool     | `true`                                                                                                                                                                                        | Yes      | Automatically link new subdomain host entities (e.g. `sub.example.com`) to their registrable domain entity as `CHILD` |


### Scanning Configuration

Scanning configuration section changes the scanning behavior when invoking the request path `/scan` to scan content.

All score modifiers are configurable. Their configuration keys use the `modifier_` prefix, and each is consumed by
`ScannedContent` when it calculates scan results. Override them with the corresponding environment variable.

| Name                                                            | Environment Variable                                                       | Default | Description                                 |
|-----------------------------------------------------------------|----------------------------------------------------------------------------|--------:|---------------------------------------------|
| `scanning.modifier_author_blacklisted`                          | `FEDERATION_SCANNING_MODIFIER_AUTHOR_BLACKLISTED`                          | `-20.0` | Blacklisted author                          |
| `scanning.modifier_author_permanently_blacklisted`              | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PERMANENTLY_BLACKLISTED`              | `-35.0` | Permanently blacklisted author              |
| `scanning.modifier_author_whitelisted`                          | `FEDERATION_SCANNING_MODIFIER_AUTHOR_WHITELISTED`                          |  `20.0` | Whitelisted author                          |
| `scanning.modifier_author_good_reputation`                      | `FEDERATION_SCANNING_MODIFIER_AUTHOR_GOOD_REPUTATION`                      |  `20.0` | Author with good reputation                 |
| `scanning.modifier_author_bad_reputation`                       | `FEDERATION_SCANNING_MODIFIER_AUTHOR_BAD_REPUTATION`                       | `-25.0` | Author with bad reputation                  |
| `scanning.modifier_author_parent_blacklisted`                   | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PARENT_BLACKLISTED`                   | `-15.0` | Blacklisted author parent                   |
| `scanning.modifier_author_parent_permanently_blacklisted`       | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PARENT_PERMANENTLY_BLACKLISTED`       | `-25.0` | Permanently blacklisted author parent       |
| `scanning.modifier_author_parent_whitelisted`                   | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PARENT_WHITELISTED`                   |  `12.0` | Whitelisted author parent                   |
| `scanning.modifier_author_parent_good_reputation`               | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PARENT_GOOD_REPUTATION`               |  `10.0` | Author parent with good reputation          |
| `scanning.modifier_author_parent_bad_reputation`                | `FEDERATION_SCANNING_MODIFIER_AUTHOR_PARENT_BAD_REPUTATION`                | `-12.0` | Author parent with bad reputation           |
| `scanning.modifier_named_entity_blacklisted`                    | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_BLACKLISTED`                    |  `-8.0` | Blacklisted named entity                    |
| `scanning.modifier_named_entity_permanently_blacklisted`        | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PERMANENTLY_BLACKLISTED`        | `-13.0` | Permanently blacklisted named entity        |
| `scanning.modifier_named_entity_whitelisted`                    | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_WHITELISTED`                    |   `8.0` | Whitelisted named entity                    |
| `scanning.modifier_named_entity_good_reputation`                | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_GOOD_REPUTATION`                |   `5.0` | Named entity with good reputation           |
| `scanning.modifier_named_entity_bad_reputation`                 | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_BAD_REPUTATION`                 | `-10.0` | Named entity with bad reputation            |
| `scanning.modifier_named_entity_parent_blacklisted`             | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PARENT_BLACKLISTED`             |  `-5.0` | Blacklisted named-entity parent             |
| `scanning.modifier_named_entity_parent_permanently_blacklisted` | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED` |  `-8.0` | Permanently blacklisted named-entity parent |
| `scanning.modifier_named_entity_parent_whitelisted`             | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PARENT_WHITELISTED`             |   `5.0` | Whitelisted named-entity parent             |
| `scanning.modifier_named_entity_parent_good_reputation`         | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PARENT_GOOD_REPUTATION`         |   `3.0` | Named-entity parent with good reputation    |
| `scanning.modifier_named_entity_parent_bad_reputation`          | `FEDERATION_SCANNING_MODIFIER_NAMED_ENTITY_PARENT_BAD_REPUTATION`          |  `-5.0` | Named-entity parent with bad reputation     |
| `scanning.modifier_classification_normal`                       | `FEDERATION_SCANNING_MODIFIER_CLASSIFICATION_NORMAL`                       |   `1.0` | Normal classification                       |
| `scanning.modifier_classification_suspicious`                   | `FEDERATION_SCANNING_MODIFIER_CLASSIFICATION_SUSPICIOUS`                   | `-10.0` | Suspicious classification                   |
| `scanning.modifier_classification_malicious`                    | `FEDERATION_SCANNING_MODIFIER_CLASSIFICATION_MALICIOUS`                    | `-25.0` | Malicious classification                    |

| Name                                  | Environment Variable                             | Type  | Default | Description                                  |
|---------------------------------------|--------------------------------------------------|-------|--------:|----------------------------------------------|
| `scanning.auto_report`                | `FEDERATION_SCANNING_AUTO_REPORT`                | bool  |  `true` | Enable automatic report generation           |
| `scanning.auto_report_threshold`      | `FEDERATION_SCANNING_AUTO_REPORT_THRESHOLD`      | float |  `80.0` | Risk score that triggers automatic reporting |
| `scanning.auto_report_caution`        | `FEDERATION_SCANNING_AUTO_REPORT_CAUTION`        | bool  | `false` | Also report scans suggesting `CAUTION`       |
| `scanning.action_block_threshold`     | `FEDERATION_SCANNING_ACTION_BLOCK_THRESHOLD`     | float |  `80.0` | Risk score that suggests blocking content    |
| `scanning.action_caution_threshold`   | `FEDERATION_SCANNING_ACTION_CAUTION_THRESHOLD`   | float |  `60.0` | Risk score that suggests caution             |
| `scanning.reputation_window_duration` | `FEDERATION_SCANNING_REPUTATION_WINDOW_DURATION` | int   |  `3600` | Reputation window duration in seconds        |
| `scanning.reputation_gain`            | `FEDERATION_SCANNING_REPUTATION_GAIN`            | int   |     `1` | Reputation gained per clean activity window  |
| `scanning.reputation_min_bound`       | `FEDERATION_SCANNING_REPUTATION_MIN_BOUND`       | int   | `-1000` | Minimum stored reputation                    |
| `scanning.reputation_max_bound`       | `FEDERATION_SCANNING_REPUTATION_MAX_BOUND`       | int   |  `1000` | Maximum stored reputation                    |
| `scanning.risk_score_neutral_point`   | `FEDERATION_SCANNING_RISK_SCORE_NEUTRAL_POINT`   | float |  `50.0` | Neutral risk score                           |
| `scanning.risk_score_scaling_factor`  | `FEDERATION_SCANNING_RISK_SCORE_SCALING_FACTOR`  | float |   `2.3` | Scan-result to risk-score scaling factor     |
| `scanning.risk_score_min_bound`       | `FEDERATION_SCANNING_RISK_SCORE_MIN_BOUND`       | float |   `0.0` | Minimum returned risk score                  |
| `scanning.risk_score_max_bound`       | `FEDERATION_SCANNING_RISK_SCORE_MAX_BOUND`       | float | `100.0` | Maximum returned risk score                  |

When a report is closed with a classification flag, the reputation of the reported entity is adjusted, and optionally
the reputation of every existing entity mentioned in the report's evidence (resolved the same way `/scan` resolves
named entities). Positive adjustments are clamped to `0`–`10` and negative adjustments to `0` or below.

| Name                                                  | Environment Variable                                             | Type | Default | Description                                                |
|-------------------------------------------------------|------------------------------------------------------------------|------|--------:|------------------------------------------------------------|
| `scanning.report_reputation_normal`                   | `FEDERATION_SCANNING_REPORT_REPUTATION_NORMAL`                   | int  |     `1` | Reported entity adjustment for a `NORMAL` report           |
| `scanning.report_reputation_suspicious`               | `FEDERATION_SCANNING_REPORT_REPUTATION_SUSPICIOUS`               | int  |   `-10` | Reported entity adjustment for a `SUSPICIOUS` report       |
| `scanning.report_reputation_malicious`                | `FEDERATION_SCANNING_REPORT_REPUTATION_MALICIOUS`                | int  |   `-20` | Reported entity adjustment for a `MALICIOUS` report        |
| `scanning.report_named_entity_reputation`             | `FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION`             | bool |  `true` | Whether entities mentioned in the evidence are adjusted    |
| `scanning.report_named_entity_reputation_normal`      | `FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION_NORMAL`      | int  |     `2` | Mentioned entity adjustment for a `NORMAL` report          |
| `scanning.report_named_entity_reputation_suspicious`  | `FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION_SUSPICIOUS`  | int  |    `-5` | Mentioned entity adjustment for a `SUSPICIOUS` report      |
| `scanning.report_named_entity_reputation_malicious`   | `FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION_MALICIOUS`   | int  |   `-10` | Mentioned entity adjustment for a `MALICIOUS` report       |

When an entity is blacklisted, its reputation is lowered, and so is the reputation of the entities directly related to
it: the entity it references as its relationship target, and the entities that reference it. Both adjustments are
clamped to `0` or below; `0` disables the adjustment.

| Name                                    | Environment Variable                               | Type | Default | Description                                 |
|-----------------------------------------|----------------------------------------------------|------|--------:|---------------------------------------------|
| `scanning.blacklist_reputation`         | `FEDERATION_SCANNING_BLACKLIST_REPUTATION`         | int  |   `-50` | Blacklisted entity adjustment               |
| `scanning.blacklist_related_reputation` | `FEDERATION_SCANNING_BLACKLIST_RELATED_REPUTATION` | int  |   `-10` | Adjustment for each directly related entity |


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
| `redis.search_cache_ttl`                        | `FEDERATION_SEARCH_CACHE_TTL`                                | int      | `60`                                                                                                                                                                                          | Yes      | TTL for cached search/listing result sets in seconds        |

### Search Configuration

This configuration is responsible for configuring the global search functionality, dictating which resource types
are searchable and whether unauthenticated users are allowed to perform searches.

| Name                        | Environment Variable                   | Type | Default Value | Required | Description                                                                                                                                                 |
|-----------------------------|----------------------------------------|------|---------------|----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `search.enabled`            | `FEDERATION_SEARCH_ENABLED`            | bool | `true`        | Yes      | Whether the search functionality is enabled                                                                                                                 |
| `search.public_search`      | `FEDERATION_SEARCH_PUBLIC`             | bool | `false`       | Yes      | Whether search is publicly accessible without authentication                                                                                                |
| `search.max_limit`          | `FEDERATION_SEARCH_MAX_LIMIT`          | int  | `50`          | Yes      | Maximum number of results returned per resource type                                                                                                        |
| `search.extended_search`    | `FEDERATION_SEARCH_EXTENDED`           | bool | `true`        | Yes      | Whether searches by authenticated operators also match extended record members such as entity metadata (unindexed, never used for unauthenticated requests) |
| `search.enable_entities`    | `FEDERATION_SEARCH_ENABLE_ENTITIES`    | bool | `true`        | Yes      | Whether entity records are included in search results                                                                                                       |
| `search.enable_evidence`    | `FEDERATION_SEARCH_ENABLE_EVIDENCE`    | bool | `true`        | Yes      | Whether evidence records are included in search results                                                                                                     |
| `search.enable_blacklist`   | `FEDERATION_SEARCH_ENABLE_BLACKLIST`   | bool | `true`        | Yes      | Whether blacklist records are included in search results                                                                                                    |
| `search.enable_reports`     | `FEDERATION_SEARCH_ENABLE_REPORTS`     | bool | `true`        | Yes      | Whether report records are included in search results                                                                                                       |
| `search.enable_attachments` | `FEDERATION_SEARCH_ENABLE_ATTACHMENTS` | bool | `false`       | Yes      | Whether file attachment records are included in search results                                                                                              |
| `search.enable_audit_logs`  | `FEDERATION_SEARCH_ENABLE_AUDIT_LOGS`  | bool | `true`        | Yes      | Whether audit log entries are included in search results                                                                                                    |
| `search.enable_operators`   | `FEDERATION_SEARCH_ENABLE_OPERATORS`   | bool | `true`        | Yes      | Whether operator records are included in search results                                                                                                     |

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


### Plugins Configuration

The `plugins` configuration is the list of plugins FederationLib loads, referenced by their ncc package name. Plugins
are responsible for their own configuration (see [Plugins](#plugins) and [PLUGINS.md](PLUGINS.md)).

```yaml
plugins:
    - net.nosial.test_plugin
    - com.example.another_plugin
```

| Name      | Environment Variable | Type  | Default Value | Required | Description                                                                                  |
|-----------|----------------------|-------|---------------|----------|----------------------------------------------------------------------------------------------|
| `plugins` | `FEDERATION_PLUGINS` | array | `[]`          | No       | The package names of the plugins to load, the environment variable is a comma-separated list |

### Bayesian Model Configuration

This configuration is responsible for the Bayesian model check of `federationlib init` (see
[Server Usage](#server-usage)), which only runs if BayesianPlugin (`net.nosial.bayesian_plugin`) is enabled and both
scripts exist. The defaults match the docker image.

| Name                              | Environment Variable                         | Type   | Default Value                                | Required | Description                                                                                                    |
|-----------------------------------|----------------------------------------------|--------|----------------------------------------------|----------|----------------------------------------------------------------------------------------------------------------|
| `bayesian_model.enabled`          | `FEDERATION_BAYESIAN_MODEL_ENABLED`          | bool   | `true`                                       | Yes      | Whether `federationlib init` checks the Bayesian model and rebuilds it when it's broken                        |
| `bayesian_model.start_script`     | `FEDERATION_BAYESIAN_MODEL_START_SCRIPT`     | string | `/usr/local/bin/temporary_start_bayesian.sh` | Yes      | The script that starts BayesianServer temporarily, it must only exit once the server is reachable              |
| `bayesian_model.stop_script`      | `FEDERATION_BAYESIAN_MODEL_STOP_SCRIPT`      | string | `/usr/local/bin/stop_temporary_bayesian.sh`  | Yes      | The script that stops the temporarily started BayesianServer                                                   |
| `bayesian_model.model_path`       | `FEDERATION_BAYESIAN_MODEL_PATH`             | string | `/var/www/bayesian_model/model`              | Yes      | BayesianServer's model directory                                                                               |
| `bayesian_model.backup_path`      | `FEDERATION_BAYESIAN_MODEL_BACKUP_PATH`      | string | `/var/www/bayesian_model/backups`            | Yes      | The directory the model directory is moved to (in a timestamped directory) before the model is rebuilt         |
| `bayesian_model.minimum_evidence` | `FEDERATION_BAYESIAN_MODEL_MINIMUM_EVIDENCE` | int    | `20`                                         | Yes      | The minimum number of classified evidence records with text content required to train a new or empty model     |
| `bayesian_model.learning_timeout` | `FEDERATION_BAYESIAN_MODEL_LEARNING_TIMEOUT` | int    | `600`                                        | Yes      | The maximum number of seconds to wait for BayesianServer to accept and process the training of a rebuilt model |

The scripts of the docker image also accept `BAYESIAN_START_TIMEOUT` (default `300`), the number of seconds to wait for
BayesianServer to become reachable before the model is considered broken, and `BAYESIAN_STOP_TIMEOUT` (default `180`),
the number of seconds to wait for BayesianServer to save the model and stop before it's killed. Increase them for large
models.


## Plugins

FederationLib can be extended with plugins, ncc packages that can add new API routes, hook into FederationLib's own
routes, react to audit log entries and changes written to the database, take part in content scanning and change the
results of entity queries.

To use a plugin, install its package with ncc and add its package name to the [`plugins`](#plugins-configuration)
configuration (`FEDERATION_PLUGINS`). With Docker, `REQUIRE_PLUGINS` installs plugins before the server starts:

```yaml
environment:
  - REQUIRE_PLUGINS=nosial/plugin1@github # Installs the plugin
  - FEDERATION_PLUGINS=net.nosial.plugin1 # Enables the plugin
```

`federationlib init` validates every enabled plugin and fails if any of them is not correctly configured. Each plugin
has its own configuration, refer to the plugin's documentation.

See [PLUGINS.md](PLUGINS.md) for how plugins work, how to use them and how to develop them.


# License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.