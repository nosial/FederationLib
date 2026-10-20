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
    * [Scheduled Jobs](#scheduled-jobs)
    * [BayesianServer Memory](#bayesianserver-memory)
    * [BayesianServer Embeddings](#bayesianserver-embeddings)
    * [BayesianServer Placeholder Rules](#bayesianserver-placeholder-rules)
    * [Disabling Bayesian Classification](#disabling-bayesian-classification)
    * [Locally testing/development](#locally-testingdevelopment)
    * [Command-Line Interface](#command-line-interface)
  * [Configuration](#configuration)
    * [Server configuration](#server-configuration)
    * [Remote Retrieval Configuration](#remote-retrieval-configuration)
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
   - `backups/`: The backups of the models (and their archives) that were replaced by `federationlib init`, one
     timestamped directory per backup
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
 - A broken model is rebuilt: the server is stopped, the `model/` directory and `archive.csv` are moved to
   `backups/<timestamp>/` (the archive would otherwise contain every document twice), the server is started again
   with a new model and archive, which is then trained with every classified evidence record from the
   oldest to the newest (the same way BayesianPlugin trains the model when evidence is classified) if there are enough
   of them
 - The temporary server is stopped with `stop_temporary_bayesian.sh`, saving the model, before the services
   (including BayesianServer) are started

The container does not start if the model is broken and could not be repaired. The check is skipped if BayesianPlugin
is not enabled or if the scripts are not available (eg; outside the docker image), see
[Bayesian Model Configuration](#bayesian-model-configuration).

The entrypoint also accepts the following docker-only environment variables, processed in this order before
`federationlib init`. The container does not start if any step fails.

| Environment Variable         | Description                                                                                                                                                                                  |
|------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `AUTOSTART`                  | The path to a shell script executed on every container start, eg; to install additional services or dependencies required by plugins. Executed directly if executable, otherwise with `bash` |
| `REQUIRE_PLUGINS`            | A comma-separated list of plugin packages to install or update, see [Plugins](#plugins)                                                                                                      |
| `CRONTAB`                    | The path to a crontab of scheduled jobs (eg; a mounted file) replacing the bundled [crontab](crontab), see [Scheduled Jobs](#scheduled-jobs)                                                 |
| `BAYESIAN_EMBEDDINGS`        | `true` adds a sentence embedding model to BayesianServer, see [BayesianServer Embeddings](#bayesianserver-embeddings)                                                                        |
| `BAYESIAN_PLACEHOLDER_RULES` | Placeholder rules file of BayesianServer (default: the bundled Telegram rules), `none` for its generic rules, see [BayesianServer Placeholder Rules](#bayesianserver-placeholder-rules)      |
| `BAYESIAN_DISABLED`          | `true` disables Bayesian classification entirely, see [Disabling Bayesian Classification](#disabling-bayesian-classification)                                                                |

### Scheduled Jobs

The docker image runs scheduled jobs with [supercronic](https://github.com/aptible/supercronic), a supervisord
service next to the web server. The bundled [crontab](crontab) runs three hourly jobs: `federationlib reputation settle`, which applies the reputation of completed days, `federationlib maintenance`, which
does nothing if maintenance is disabled (`FEDERATION_MAINTENANCE_ENABLED`), and `federationlib bayesian sync`, which
teaches BayesianServer the evidence classifications it missed (eg; while it was restarting). Jobs run with the environment of the container
(so they use the same configuration as the server), with their output in the container logs, and a job does not
start again while its previous run is still running.

To run other jobs, mount a crontab and set `CRONTAB` to its path, it replaces the bundled crontab so include the
maintenance and reputation jobs if they should keep running. Running jobs with `nice`/`ionice` keeps them from slowing down the web
server:

```yaml
volumes:
  - ./crontab:/etc/federation/custom_crontab:ro
environment:
  - CRONTAB=/etc/federation/custom_crontab
```

```cron
0 * * * * nice -n 10 ionice -c3 /usr/local/bin/federationlib maintenance
15 * * * * nice -n 10 /usr/local/bin/federationlib reputation settle
*/15 * * * * nice -n 10 ionice -c3 /usr/local/bin/my-plugin-job
```

### BayesianServer Memory

The bundled BayesianServer runs with a maximum heap of 4 GB by default, the most memory its model and training data
can use. To give it more (or less), set `BAYESIAN_MAX_MEMORY` on the container to a size with a `k`, `m`, `g` or `t`
suffix, or to a percentage of the memory available to the container, and restart it:

```yaml
services:
  app:
    environment:
      - BAYESIAN_MAX_MEMORY=16g   # or eg; 16384m, or 50% of the container's memory
```

With a percentage, the memory available to the container is its memory limit (eg; `deploy.resources.limits.memory`
or `docker run --memory`), or the memory of the host if it has none. The container does not start if the value is
invalid. The current maximum heap is the `available_memory_bytes` of BayesianServer's diagnostics:

```shell
docker exec federation curl -s localhost:6380/ | jq '.server.available_memory_bytes, .server.current_memory_bytes'
```

### BayesianServer Embeddings

Setting `BAYESIAN_EMBEDDINGS=true` adds a sentence embedding model
([multilingual-e5-small](https://huggingface.co/intfloat/multilingual-e5-small)) to the bundled BayesianServer: its
classifier is averaged with the word and character features, so spam is recognized in other words and languages. The
model (about 490 MB) is downloaded on the first start into the `embedding` directory of the Bayesian model volume, from
`BAYESIAN_EMBEDDING_URL` (default `https://huggingface.co/intfloat/multilingual-e5-small/resolve/main/onnx`, which holds
`model.onnx` and `tokenizer.json`). If the download fails the container starts without it and tries again on the next
start. The model is retrained with the embeddings on the first start, which takes longer than usual.

The model uses about 1 GB of memory outside BayesianServer's heap (`BAYESIAN_MAX_MEMORY`) and a few milliseconds of CPU
for every classified or newly learned message.

```yaml
services:
  app:
    environment:
      - BAYESIAN_EMBEDDINGS=true
```

### BayesianServer Placeholder Rules

The bundled BayesianServer replaces links, mentions, phone numbers, prices, numbers and emoji with placeholder words
before learning or classifying a message (`--placeholders`). The image adds rules for Telegram links
([`bayesian_recovery/placeholder_rules.json`](bayesian_recovery/placeholder_rules.json)): invites become `xtginvitex`
and other Telegram links `xtglinkx`. `BAYESIAN_PLACEHOLDER_RULES` replaces them with another rules file (eg; a mounted
file with the links of another platform, see BayesianServer's `--placeholder-rules`), or `none` uses BayesianServer's
generic rules only. Changing the rules retrains the model on the next start.

### Disabling Bayesian Classification

The docker image classifies content with the bundled BayesianServer and BayesianPlugin by default. Setting
`BAYESIAN_DISABLED=true` on the container (`true`, `1`, `yes` or `on`, default `false`) disables everything related to
it:

 - BayesianServer is not started, neither as a service nor temporarily by `federationlib init`, and the healthcheck
   doesn't check it
 - BayesianPlugin is not enabled, even if it's listed in `FEDERATION_PLUGINS`
 - The Bayesian model volume (`/var/www/bayesian_model`) is left untouched and the model is not checked
   (`FEDERATION_BAYESIAN_MODEL_ENABLED` is set to `false`)

```yaml
services:
  app:
    environment:
      - BAYESIAN_DISABLED=true
```

The model and archive in the volume are kept, so removing the option (or setting it to `false`) brings Bayesian
classification back with the model as it was. The container does not start if the value is not a boolean.

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
utility. Commands are written in the style of docker, `federationlib <command> [<arguments>...]`: run `federationlib help`
for the list of commands and `federationlib help <command>` or `federationlib <command> --help` for the usage of one.

| Command                                             | Description                                                                                                                                                     |
|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `federationlib init`                                | Initializes FederationLib's database schema and default operators, and checks the Bayesian model                                                                |
| `federationlib operators ls`                        | Lists the operators (`--page`, `--limit`)                                                                                                                       |
| `federationlib operators create <name>`             | Creates an operator and prints its access token (`--client`, `--management`, `--operator`, `--bot`, `--auto-assign-reports`, `--auto-assign-automated-reports`) |
| `federationlib operators <uuid>`                    | Shows an operator (`inspect`)                                                                                                                                   |
| `federationlib operators <uuid> set`                | Changes the permissions and configuration of an operator, eg; `set --bot true --client false`                                                                   |
| `federationlib operators <uuid> enable` / `disable` | Enables or disables an operator                                                                                                                                 |
| `federationlib operators <uuid> token`              | Generates a new access token for an operator, the previous one stops working                                                                                    |
| `federationlib operators <uuid> rm`                 | Deletes an operator                                                                                                                                             |
| `federationlib audit ls`                            | Lists audit log entries (`--page`, `--limit`, `--type`)                                                                                                         |
| `federationlib reputation settle`                   | Applies the reputation of every completed day of activity, never twice (run hourly by the docker image)                                                         |
| `federationlib maintenance`                         | Runs maintenance tasks to clean up expired records (`--archive <directory>`) and refresh the DNS details of domain entities                                     |
| `federationlib bayesian sync`                       | Teaches BayesianServer the evidence classifications it missed since the last sync (`--full` for all)                                                            |

The root and system operators cannot be modified from the command line.

## Configuration

FederationLib is extremely customizable with many different configuration options to alter, this allows deploying a
Federated Database to be fine-tuned for different services/applications. Configuration management is handled by
[ConfigLib](https://github.com/nosial/ConfigLib), configuration values can be alterted and commited to using the
`configlib` command-line utility, modifying the configuration file or setting environment variables.


Lists can be written as YAML lists or, for example in environment variables, as comma-separated values
(`FEDERATION_PUBLIC=ENTITIES,EVIDENCE,BLACKLIST`). Maps can be written as YAML mappings or as comma-separated
`KEY=VALUE` pairs (`FEDERATION_MAINTENANCE_RETENTION=EVIDENCE=1y,ENTITIES=off`). Durations are seconds, or a number with
a unit: `30s`, `10m`, `12h`, `30d`, `2w` or `2y`.

Configuration keys and environment variables of earlier releases are migrated automatically on start, with a warning in
the log naming their replacement: keys stored in the configuration file are converted to the settings that replaced
them (and removed from the file), and deprecated environment variables are converted on every start until renamed.

### Server configuration

This section is used to configured how the server operates, dictating what is permitted for public use, where data is
stored at and what limits should be applied.

The production Docker image sets `display_errors = Off` and `display_startup_errors = Off`. PHP errors remain available
to registered handlers such as LogLib2, but are not emitted into HTTP responses.

| Name                             | Environment Variable                 | Type   | Default                                                                                      | Description                                                                                                              |
|----------------------------------|--------------------------------------|--------|----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------|
| `server.base_url`                | `FEDERATION_BASE_URL`                | string | `http://127.0.0.1:7000`                                                                      | The base URL the server is served at                                                                                     |
| `server.name`                    | `FEDERATION_NAME`                    | string | `Federation Server`                                                                          | The name of the server                                                                                                   |
| `server.access_token`            | `FEDERATION_ACCESS_TOKEN`            | string | Randomly generated                                                                           | The root access token                                                                                                    |
| `server.max_upload_size`         | `FEDERATION_MAX_UPLOAD_SIZE`         | int    | `52428800` (50 MB)                                                                           | The maximum size of an uploaded file in bytes                                                                            |
| `server.storage_path`            | `FEDERATION_STORAGE_PATH`            | string | `/var/www/uploads`                                                                           | Where uploaded files are stored                                                                                          |
| `server.max_page_size`           | `FEDERATION_MAX_PAGE_SIZE`           | int    | `100`                                                                                        | The maximum number of records a list returns, and its default page size                                                  |
| `server.public`                  | `FEDERATION_PUBLIC`                  | list   | `AUDIT_LOGS`, `EVIDENCE`, `BLACKLIST`, `ENTITIES`, `REPORTS`, `SCAN_CONTENT`, `QUERY_ENTITY` | What is accessible without authentication, also `ENTITY_METADATA`, `SEARCH` (the global search) and `TELEPHONE_ENTITIES` |
| `server.public_audit_entries`    | `FEDERATION_PUBLIC_AUDIT_ENTRIES`    | list   | The default public audit log types                                                           | The audit log entry types visible without authentication when `AUDIT_LOGS` is public                                     |
| `server.top_threats_limit`       | `FEDERATION_TOP_THREATS_LIMIT`       | int    | `25`                                                                                         | The maximum number of entities returned by top threats                                                                   |
| `server.min_blacklist_time`      | `FEDERATION_MIN_BLACKLIST_TIME`      | int    | `1800` (30 min)                                                                              | The shortest temporary blacklist in seconds                                                                              |
| `server.allow_illegal_content`   | `FEDERATION_ALLOW_ILLEGAL_CONTENT`   | bool   | `true`                                                                                       | Whether reports of illegal content are accepted                                                                          |
| `server.link_subdomain_entities` | `FEDERATION_LINK_SUBDOMAIN_ENTITIES` | bool   | `true`                                                                                       | Automatically link new subdomain host entities (e.g. `sub.example.com`) to their registrable domain entity as `CHILD`    |

`TELEPHONE_ENTITIES` exposes telephone entities (`<number>@e164.arpa`) to unauthenticated requests like other entities;
without it they are only returned to requests that already know the number.

### Remote Retrieval Configuration

The server can be made to retrieve URLs chosen by a requester: URL uploads of attachments, and URLs submitted for scanning,
which FederationLib never retrieves itself but plugins may. Both are guarded against server-side request forgery the same
way. DNS lookups of scanned names are a separate scanning setting (`scanning.dns_lookup_limit`).

| Name                             | Environment Variable                        | Type     | Default | Description                                                                                                 |
|----------------------------------|---------------------------------------------|----------|---------|-------------------------------------------------------------------------------------------------------------|
| `remote_retrieval.uploads`       | `FEDERATION_REMOTE_RETRIEVAL_UPLOADS`       | bool     | `true`  | Whether attachments may be uploaded from a URL the server retrieves itself                                  |
| `remote_retrieval.scans`         | `FEDERATION_REMOTE_RETRIEVAL_SCANS`         | bool     | `false` | Whether plugins may retrieve URLs submitted for scanning, a security risk: keep it disabled unless required |
| `remote_retrieval.timeout`       | `FEDERATION_REMOTE_RETRIEVAL_TIMEOUT`       | duration | `30`    | The maximum time a retrieval may take                                                                       |
| `remote_retrieval.allow_private` | `FEDERATION_REMOTE_RETRIEVAL_ALLOW_PRIVATE` | bool     | `false` | Whether retrievals may connect to private, loopback, link-local or reserved addresses                       |

### Scanning Configuration

Scanning configuration section changes the scanning behavior when invoking the request path `/scan` to scan content.

Every scanning rule (eg; `AUTHOR_BLACKLISTED`, `NAMED_ENTITY_BAD_REPUTATION`, `CLASSIFICATION_MALICIOUS`) has default
points, consumed by `ScannedContent` when it calculates scan results; `scanning.modifiers` overrides the points of the
rules it names, eg; `FEDERATION_SCANNING_MODIFIERS=AUTHOR_BLACKLISTED=-25,AUTHOR_WHITELISTED=15`. The risk score starts at
`50` and is bounded by `0` and `100`, and reputation is bounded by `-1000` and `1000`, as OFD requires.

| Name                                      | Environment Variable                                 | Type     | Default                                       | Description                                                                                                                                |
|-------------------------------------------|------------------------------------------------------|----------|-----------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `scanning.modifiers`                      | `FEDERATION_SCANNING_MODIFIERS`                      | map      | none                                          | Points of scanning rules that override their defaults, keyed by rule name                                                                  |
| `scanning.auto_report`                    | `FEDERATION_SCANNING_AUTO_REPORT`                    | string   | `block`                                       | Which scans generate a report: `block` (the risk score reaches the block threshold), `caution` (it reaches the caution threshold) or `off` |
| `scanning.action_block_threshold`         | `FEDERATION_SCANNING_ACTION_BLOCK_THRESHOLD`         | float    | `80.0`                                        | The risk score from which `BLOCK_CONTENT` is suggested                                                                                     |
| `scanning.action_caution_threshold`       | `FEDERATION_SCANNING_ACTION_CAUTION_THRESHOLD`       | float    | `60.0`                                        | The risk score from which `CAUTION` is suggested                                                                                           |
| `scanning.risk_score_scaling_factor`      | `FEDERATION_SCANNING_RISK_SCORE_SCALING_FACTOR`      | float    | `2.3`                                         | Scan-result to risk-score scaling factor                                                                                                   |
| `scanning.reputation_gain`                | `FEDERATION_SCANNING_REPUTATION_GAIN`                | int      | `10`                                          | Reputation gained for a day of normal activity, `0` to `10`, tapering above 500                                                            |
| `scanning.reputation_loss`                | `FEDERATION_SCANNING_REPUTATION_LOSS`                | int      | `-5`                                          | Reputation lost by an author for a day with content that reached the block threshold, `0` only holds back the gain                         |
| `scanning.mention_min_authors`            | `FEDERATION_SCANNING_MENTION_MIN_AUTHORS`            | int      | `3`                                           | Distinct authors that must mention an entity in normal content on a day for it to gain reputation                                          |
| `scanning.report_reputation`              | `FEDERATION_SCANNING_REPORT_REPUTATION`              | map      | `NORMAL=1`, `SUSPICIOUS=-10`, `MALICIOUS=-20` | Reputation adjustment of the reported entity when a report is closed with each classification flag                                         |
| `scanning.report_named_entity_reputation` | `FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION` | map/bool | `NORMAL=1`, `SUSPICIOUS=-5`, `MALICIOUS=-10`  | The same for every existing entity named in the report's evidence, `false` disables it                                                     |
| `scanning.blacklist_reputation`           | `FEDERATION_SCANNING_BLACKLIST_REPUTATION`           | int      | `-50`                                         | Reputation adjustment of a blacklisted entity                                                                                              |
| `scanning.blacklist_related_reputation`   | `FEDERATION_SCANNING_BLACKLIST_RELATED_REPUTATION`   | int      | `-10`                                         | Reputation adjustment of the entities directly related to a blacklisted entity                                                             |
| `scanning.concerns`                       | `FEDERATION_SCANNING_CONCERNS`                       | list     | every incident type                           | Concerns (incident types) the server can assess, published as `concerns`                                                                   |
| `scanning.default_concerns`               | `FEDERATION_SCANNING_DEFAULT_CONCERNS`               | list     | all except `ADVERTISING`, `TRACKING`          | Concerns assessed when a scan or entity query names none, published as `default_concerns`                                                  |
| `scanning.dns_lookup_limit`               | `FEDERATION_SCANNING_DNS_LOOKUP_LIMIT`               | int      | `10`                                          | Maximum DNS lookups of `HOSTNAME`, `URL` and `WEB_PAGE` names per scan request, `0` disables DNS lookups                                   |

Reputation is built from scans by authenticated clients over time, never from anonymous scans. The activity of each
entity is recorded per day (UTC), and every completed day is applied by `federationlib reputation settle` (run every hour
by the docker image), at most one change per entity per day, so reputation rises gradually rather than per message:

 - The author of scanned content gains `reputation_gain` for a day of normal content. A day with content that warranted
   caution earns nothing, and a day with content that reached the block threshold costs it `reputation_loss`. A
   blacklisted author earns nothing while the blacklist is in effect.
 - An entity mentioned in scanned content, such as `youtube.com` in a shared link, gains `reputation_gain` for a day on
   which at least `mention_min_authors` distinct authors mentioned it in normal content and none in content that warranted
   caution. Requiring several authors keeps a single account from building up the reputation of a domain it promotes;
   mentions never cost reputation.
 - A gain also credits the parent of a `CHILD` entity for the day, eg; the registrable domain of a subdomain.
 - Gains are full up to a reputation of 500 and taper towards 1000, so an entity active every day reaches 500 in about
   two months and approaches 1000 much more slowly. Whitelisted entities are never adjusted.

When Redis is enabled, the activity of each scan is buffered in Redis (one pipelined round trip, keys prefixed
`reputation_activity:`) instead of being written to the database, and `federationlib reputation settle` writes the
buffer to the database in batches before it settles. Buffered activity expires after 7 days if it is never settled, so
the Redis instance should not evict keys under memory pressure (`maxmemory-policy noeviction` or a `volatile-*` policy
with enough memory) for activity not to be lost. Without Redis, or when it fails, scans write their activity directly.

When a report is closed with a classification flag, the reputation of the reported entity is adjusted, and optionally
the reputation of every existing entity mentioned in the report's evidence (resolved the same way `/scan` resolves
named entities). Positive adjustments are clamped to `0`–`10` and negative adjustments to `0` or below.

When an entity is blacklisted, its reputation is lowered, and so is the reputation of the entities directly related to
it through an `ALTERNATIVE` or `CHILD` relationship, in either direction: those that share its actor or depend on it. A
`PROXY` relays for many actors, so it is neither lowered by nor lowers its target. Both adjustments are clamped to `0` or
below; `0` disables the adjustment.

`collects_public_scan_content` is published as `true` when scanning is public and `auto_report` is not `off`, since
automatic reports record the scanned content as evidence, or when a plugin declares that it stores scanned content
(see [PLUGINS.md](PLUGINS.md)).

### Database Configuration

This configuration section is responsible for configuring the database connection to MariaDB, the character set is
always `utf8mb4`.

| Name                | Environment Variable           | Type   | Default      | Description            |
|---------------------|--------------------------------|--------|--------------|------------------------|
| `database.host`     | `FEDERATION_DATABASE_HOST`     | string | `127.0.0.1`  | The database host      |
| `database.port`     | `FEDERATION_DATABASE_PORT`     | int    | `3306`       | The database port      |
| `database.username` | `FEDERATION_DATABASE_USERNAME` | string | `root`       | The database username  |
| `database.password` | `FEDERATION_DATABASE_PASSWORD` | string | `root`       | The database password  |
| `database.name`     | `FEDERATION_DATABASE_NAME`     | string | `federation` | The database name      |

### Redis/Caching configuration

This configuration section is responsible for configuring the redis connection and object caching to improve server-side
response performance. `redis.record_cache` sets, per record type (`OPERATOR`, `ENTITY`, `FILE_ATTACHMENT`, `EVIDENCE`,
`AUDIT_LOG`, `BLACKLIST`, `REPORT`), the maximum number of cached records and their TTL, as `{limit: 5000, ttl: 600}` or
`ENTITY=5000/600`; a limit of `0` disables the cache of the record type. Record types that are not configured keep their
defaults.

| Name                      | Environment Variable               | Type     | Default                                                                                 | Description                                                    |
|---------------------------|------------------------------------|----------|-----------------------------------------------------------------------------------------|----------------------------------------------------------------|
| `redis.enabled`           | `FEDERATION_REDIS_ENABLED`         | bool     | `false`                                                                                 | Whether the cache layer is enabled                             |
| `redis.host`              | `FEDERATION_REDIS_HOST`            | string   | `127.0.0.1`                                                                             | The Redis host                                                 |
| `redis.port`              | `FEDERATION_REDIS_PORT`            | int      | `6379`                                                                                  | The Redis port                                                 |
| `redis.password`          | `FEDERATION_REDIS_PASSWORD`        | string   | none                                                                                    | The Redis password                                             |
| `redis.database`          | `FEDERATION_REDIS_DATABASE`        | int      | `0`                                                                                     | The Redis database index                                       |
| `redis.throw_on_errors`   | `FEDERATION_CACHE_THROW_ON_ERRORS` | bool     | `true`                                                                                  | Whether cache errors fail the request rather than being logged |
| `redis.pre_cache_enabled` | `FEDERATION_PRE_CACHE_ENABLED`     | bool     | `true`                                                                                  | Whether records are cached before they are requested           |
| `redis.record_cache`      | `FEDERATION_REDIS_RECORD_CACHE`    | map      | entities 5000, evidence and blacklist 3000, file attachments 2000, others 1000; TTL 600 | The cache limit and TTL of each record type                    |
| `redis.search_cache_ttl`  | `FEDERATION_SEARCH_CACHE_TTL`      | duration | `60`                                                                                    | How long cached search and listing results live                |

### Search Configuration

This configuration is responsible for configuring the global search functionality, dictating which resource types are
searchable. Whether unauthenticated users can use the global search is `SEARCH` in `server.public`.

| Name                     | Environment Variable          | Type | Default                                                              | Description                                                                   |
|--------------------------|-------------------------------|------|----------------------------------------------------------------------|-------------------------------------------------------------------------------|
| `search.types`           | `FEDERATION_SEARCH_TYPES`     | list | `ENTITY`, `EVIDENCE`, `BLACKLIST`, `REPORT`, `AUDIT_LOG`, `OPERATOR` | The searchable record types, also `ATTACHMENT`; an empty list disables search |
| `search.max_limit`       | `FEDERATION_SEARCH_MAX_LIMIT` | int  | `50`                                                                 | The maximum number of search results per record type                          |
| `search.extended_search` | `FEDERATION_SEARCH_EXTENDED`  | bool | `true`                                                               | Whether authenticated operators also search columns that are not indexed      |

### Maintenance Configuration

This configuration is responsible for configuring how the maintenance command (`federationlib maintenance`, run every
hour by the docker image, see [Scheduled Jobs](#scheduled-jobs)) operates, dictating how long database records should
be retained before they are considered eligible for cleanup. `maintenance.retention` sets the retention of each record
type (`AUDIT_LOGS`, `BLACKLIST`, `EVIDENCE`, `REPORTS`, `FILE_ATTACHMENTS`, `ENTITIES`) as a duration, or `off` to keep
the records forever; record types that are not configured keep their defaults:

 - File attachments and evidence are removed when they were created before their retention, along with their stored
   files. Removing evidence also removes its file attachments.
 - Only closed reports are removed, when they were last changed (eg; closed) before the retention. Open reports are kept.
 - Only blacklist records no longer in effect are removed: expired records when they expired before the retention, and
   lifted records when they were created before the retention. Permanent and unexpired blacklist records are kept.
 - Entities are only removed when they were created, last updated and their reputation last changed before the
   retention, and they have no evidence, reports or blacklist records left. Whitelisted entities are kept.
 - Audit log entries are removed when they were created before the retention.

Records are removed in batches without dispatching record changes to plugins, `--archive <directory>` saves them as
CSV files before they are removed.

After cleaning, maintenance resolves the domain entities (host entities whose host is a DNS name, not an IP address or a
telephone number) whose DNS details are missing or were resolved longer than `maintenance.dns_refresh` ago, and stores
the details in their metadata, at most 500 per run (those never resolved first, the rest on the next runs):

| Metadata key                                         | Value                                                                                               |
|------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `dns_resolved`                                       | When the name was last resolved (Unix timestamp)                                                    |
| `dns_status`                                         | `RESOLVED`, `NO_RECORDS` (none of the record types exist) or `FAILED` (eg; the name does not exist) |
| `dns_a`, `dns_aaaa`, `dns_cname`, `dns_mx`, `dns_ns` | The records of each type, comma-separated (MX as `<priority> <host>`), absent when there are none   |

The other metadata keys of the entity are kept. The details are maintained by the server, so refreshing them neither
changes the `updated` timestamp of the entity nor postpones its removal by maintenance. Entity metadata is only public
when `ENTITY_METADATA` is in `server.public`.

| Name                      | Environment Variable                 | Type     | Default                                                      | Description                                                                                           |
|---------------------------|--------------------------------------|----------|--------------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| `maintenance.enabled`     | `FEDERATION_MAINTENANCE_ENABLED`     | bool     | `true`                                                       | Whether `federationlib maintenance` runs                                                              |
| `maintenance.retention`   | `FEDERATION_MAINTENANCE_RETENTION`   | map      | `BLACKLIST=1y`, `ENTITIES=off`, every other record type `2y` | How long records of each type are kept                                                                |
| `maintenance.dns_refresh` | `FEDERATION_MAINTENANCE_DNS_REFRESH` | duration | `90d`                                                        | How long the DNS details of a domain entity are kept before it is resolved again, `off` disables them |

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
| `bayesian_model.archive_path`     | `FEDERATION_BAYESIAN_MODEL_ARCHIVE_PATH`     | string | `/var/www/bayesian_model/archive.csv`        | Yes      | BayesianServer's training archive, backed up with the model                                                    |
| `bayesian_model.backup_path`      | `FEDERATION_BAYESIAN_MODEL_BACKUP_PATH`      | string | `/var/www/bayesian_model/backups`            | Yes      | The directory the model and archive are moved to (in a timestamped directory) before the model is rebuilt      |
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