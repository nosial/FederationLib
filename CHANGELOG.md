# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - Ongoing

This update introduces support for OFD Specification version 2.2

### Added
 - Implements the scanned file URLs of version 2.2 of the OFD Specification, the server reports `2.2` as its
   `api_version`. Evidence may name files by URL in a `file_urls` member, in JSON and `multipart/form-data` requests,
   and evidence with file URLs may omit `text_content`. The URLs are always validated (absolute HTTP or HTTPS URLs of at
   most 2048 characters without credentials), but only retrieved when `remote_retrieval.scan_files` is enabled (`false`
   by default) and the files of scan requests are as well, and only for requests with client permissions, regardless
   of the `SCAN_FILES` item of `server.public`. Otherwise they are ignored
 - Retrieved files count towards `scanning.max_files`, share one `remote_retrieval.timeout` per request and are handed
   to plugins with `ContentScan::getFiles()` after the files the request carries, their file indexes following those
   files. A file URL that is not permitted, cannot be retrieved, is empty or exceeds `scanning.max_file_size` is left
   out without an error, so the result does not reveal why. Retrieved files are deleted when the request ends unless the
   scan generates a report, in which case they are kept as attachments of its evidence like the files it carries
 - `FederationClient::scanContent()` sends file URLs with its new `$fileUrls` parameter, keyed by evidence index

### Changed
 - The retrieval of files from URLs moved from the Upload Attachment method to `RemoteRetrieval`, shared by URL
   uploads and scanned file URLs with the same protections against server-side request forgery



## [2.1.0] - 2026-10-29

This update introduces support for OFD Specification version 2.1

### Added
 - Implements the scanned files of version 2.1 of the OFD Specification, the server reports `2.1` as its `api_version`.
   Scan requests may carry files in a `multipart/form-data` body whose `evidence` part carries the JSON text of the
   evidence, each evidence object naming the parts of its files in a `files` member; an evidence object with files may
   omit `text_content`. FederationLib interprets no file itself: a file is accepted and ignored unless a plugin
   interprets its media type, so content consisting only of such files keeps the neutral risk score of `50`
 - Files are validated against `scanning.max_files` (`4` by default, `0` disables the scanning of files) and
   `scanning.max_file_size` (10 MB by default), their media type is determined from their content, and they are left in
   PHP's temporary upload directory so that nothing is stored, unless the scan generates a report: the files of each
   evidence item are then kept as attachments of its evidence, with an `ATTACHMENT_UPLOADED` audit log entry
 - Every server accepts files: when `scanning.max_files` is `0`, or the request is unauthenticated (or lacks client
   permissions) and the `SCAN_FILES` item of `server.public` is not set, the files are validated and ignored, as if
   no plugin interpreted them. `SCAN_FILES` is off by default
 - The server information publishes `max_scan_files` and `max_scan_file_size`, and detections carry a `file_index`
 - Plugins read the files of an evidence item with `ContentScan::getFiles()` (`ScanFile` objects), interpret the media
   types they support and ignore the rest, and relate detections to a file with the new `$fileIndex` parameter of
   `ContentScan::addDetection()`
 - `FileAttachmentManager::storeFile()` stores a copy of a file as an attachment of an evidence record
 - The docker images include the PHP `gd` and `exif` extensions, for plugins that interpret images

### Changed
 - `Utilities::detectMimeType()` and `Utilities::getSafeFileName()` replace the private helpers of the Upload Attachment
   method, so that scanned files are handled like uploaded attachments
 - `ReportManager::closeReport()` closes a report the way the Close Report method does: it classifies the report's
   unclassified evidence, applies the reputation, optionally blacklists the reported entity and records the closure in
   the audit log, so that plugins closing reports on their own behalf have the same effect as an operator. It takes the
   closing operator, which the report must be assigned to
 - The Close Report method closes reports with `ReportManager::closeReport()`

### Fixed
 - `FederationClient::downloadAttachment()` throws a `RequestException` with the HTTP status code of the server (eg;
   `404` for an unknown attachment) and its error message, instead of a `500` for every failed download
 - Deleting a report invalidates the cached evidence records of the report, so its evidence is no longer returned with
   a reference to the deleted report
 - The List Entity Reports, List Operator Reports and List Assigned Operator Reports methods accept the `by` and
   `order` parameters, which `FederationClient` already sent and which were ignored



## [2.0.1] - 2026-10-23

This update brings the project up to date with ncc 3.1.11

### Changed
 - Included the project's light and dark icons from NosialFrontier in the README header.
 - Updated the project to use ncc 3.1.11


## [2.0.0] - 2026-10-20

This update implements version 2.0 of the OFD Specification: operator permissions and configuration, bot operators,
content types, concerns, detections and telephone entities. Reputation is rebuilt around daily activity, the
configuration is simplified and the command-line interface follows the style of docker.

**Upgrading:** servers running 1.0.8 to 1.0.16 upgrade in place. The first start (`federationlib init`, run by the
docker entrypoint) migrates the database from schema `1.0` to `1.4`, in order and resuming from the last completed
step if interrupted, and clears the records cached in Redis and the reputation windows of earlier releases. The
migrations change populated tables in place rather than copying them. The configuration of earlier releases is
converted on every start, logging a warning for each replaced setting (see Changed).

### Added
 - Operator permissions and configuration (OFD 2.0, `api_version` is now `2.0`): operator records carry `permissions`
   (`client`, `management`, `operator`, `client` being true whenever `management` is) and `configuration`
   (`auto_assign_reports`, `auto_assign_automated_reports`, `is_bot`). `PATCH /operators/{uuid}/permissions` and
   `PATCH /operators/{uuid}/configuration` take a partial object and return the updated one; revoking client
   permissions from an operator that keeps management permissions is rejected with HTTP 409. Operators can change
   their own configuration, changing another operator's requires operator permissions, and the root and system
   operators can't be modified. `configuration` is only returned to the operator itself and to operators with
   operator permissions, anyone else receives `null`. The client adds `updateOperatorPermissions()`,
   `updateOperatorConfiguration()` and `setBot()`
 - Bot operators (`is_bot`): the evidence and reports they submit are automated. Evidence records have an `automated`
   member and `AUTOMATED`/`NOT_AUTOMATED` categories; automated evidence (created by the server or submitted by a bot)
   is always created confidential and can be made public by a manager afterwards. Existing evidence of the system
   operator is marked automated, its confidentiality is unchanged
 - Content types: evidence (scanned, submitted or reported) accepts a `content_type` (`TEXT`, `EMAIL`, `SMS`,
   `WEB_PAGE`, `HOSTNAME`, `URL`) and a `context` object validated per type. `/scan` treats a `HOSTNAME` or `URL` as
   the entity itself (falling back to its registrable domain), resolves the URL a `WEB_PAGE` declares as an unverified
   assertion, and resolves the names in `HOSTNAME`, `URL` and `WEB_PAGE` content by DNS to their public addresses (at
   most `scanning.dns_lookup_limit` per request, 10 by default). FederationLib never retrieves these URLs, plugins may
   only when `remote_retrieval.scans` is enabled (disabled by default)
 - Concerns: `/scan` accepts `concerns` and entity queries a comma-separated `concerns` parameter. Only these incident
   types (`scanning.default_concerns` when omitted, every type except `ADVERTISING` and `TRACKING` by default) affect
   the classification, risk score, suggested action and detections; blacklist records of other types are still
   returned but don't suggest blocking. Plugin scanning rules, classifications and detections relate to a concern
   (`SPAM` unless given) and are discarded for concerns that were not requested
 - `detections` in scan results (`Detection`), including one for every active blacklist record of a requested concern
   on a resolved entity or its parent, and `ContentScan::addDetection()` for plugins
 - `ADVERTISING` and `TRACKING` incident types, and a comma-separated `type` filter on `/blacklist` and
   `/blacklist/search`
 - Telephone entities (`<E.164 number>@e164.arpa`), validated on registration and lookup and extracted from content
   with [libphonenumber](https://github.com/giggsey/libphonenumber-for-php) (a new dependency): international numbers
   always, national numbers when an `SMS` declares the `region` it was received in, and only numbers valid for their
   region, so one-time codes and order numbers are ignored and every spelling of a number resolves to one entity.
   Unless `server.public` includes `TELEPHONE_ENTITIES`, they are hidden from unauthenticated requests (omitted from
   listings, searches, top threats and related entities, unresolvable by UUID and stripped of metadata). Evidence
   about a telephone entity is always confidential, and audit log messages refer to them by UUID
 - Server information members `public_telephone_entities`, `scan_content_types`, `concerns`, `default_concerns`,
   `allow_url_uploads` (treated as false by the client when absent) and `collects_public_scan_content`, which is true
   when content scanning is public and automatic reports may record scanned content as evidence, or when a plugin
   declares `collects_scan_content`
 - `report` member of audit log entries (OFD 2.0), set by report related entries: submitted, generated, assigned and
   closed reports, evidence linked to a report and blacklists referencing a report
 - Upload Attachment accepts a `url` instead of the `file` part (multipart, form-encoded or JSON), which the server
   retrieves itself, controlled by `remote_retrieval.uploads` (default `true`) and `remote_retrieval.timeout` (`30`
   seconds). Redirects are followed up to 5 times, every request is pinned to its validated address, and URLs with
   credentials or that resolve to non-public addresses are rejected unless `remote_retrieval.allow_private` is set.
   `FederationClient::uploadFileAttachmentByUrl()` uploads by URL, `uploadFileAttachmentFromUrl()` still downloads
   the file locally first
 - `STARTUP` and `MAINTENANCE` plugin events. `STARTUP` is dispatched once per start by `federationlib init`, after the
   database is created or migrated and before requests are served; a failing handler fails the start. `MAINTENANCE` is
   dispatched by every `federationlib maintenance` run, also when cleaning is disabled, with the archive directory and
   the number of records removed; a failing handler is logged and the run exits with an error
 - Maintenance stores the DNS details of domain entities (A, AAAA, CNAME, MX and NS records, lookup time and status)
   in their metadata as `dns_*` keys, refreshing those missing or older than `maintenance.dns_refresh` (default `90d`,
   `off` disables it), at most 500 entities per run. The refresh keeps the other metadata and changes neither the
   `updated` timestamp of the entity nor when maintenance removes it
 - `federationlib bayesian sync` teaches BayesianServer the evidence classifications made since the last sync, so
   classifications it missed (unavailable, restarting or a full learning queue) are learned; `--full` learns every
   classification again. Evidence records when it was classified so classifications are learned in order
 - `EVIDENCE_DELETED` record changes carry the deleted `EvidenceRecord`, so BayesianPlugin forgets deleted evidence
 - Docker: scheduled jobs run with supercronic, the bundled crontab runs `maintenance`, `reputation settle` and
   `bayesian sync` hourly and `CRONTAB` replaces it. `BAYESIAN_DISABLED` disables the bundled BayesianServer and
   BayesianPlugin (the healthcheck, now `docker-healthcheck.sh`, then skips it), `BAYESIAN_MAX_MEMORY` sets its heap
   (default `4g`, a size or a percentage of the container's memory, previously 25%), `BAYESIAN_EMBEDDINGS` adds the
   multilingual-e5-small embedding model, and `BAYESIAN_PLACEHOLDER_RULES` replaces the bundled Telegram link
   placeholder rules (`bayesian_recovery/placeholder_rules.json`) or `none` uses BayesianServer's generic rules
 - The bundled BayesianServer learns content it classified as `NORMAL` a week later unless it is classified in the
   meantime (`--implicit-delay 604800`), so the model sees ordinary traffic and not only reported content
 - CORS preflight (`OPTIONS`) requests are answered with HTTP 204 and the CORS headers without authentication, an
   `OPTIONS` request without `Access-Control-Request-Method` is still routed to a plugin route that handles it
 - Download Attachment responses carry `X-Content-Type-Options: nosniff`
 - `federationlib init` warns when the root access token is not 32 characters of the `b64token` syntax, as such a
   token can never authenticate
 - `EvidenceManager::updateClassificationFlag()` takes an optional `$overwrite` argument, and
   `EntitiesManager::getEntitiesByUuids()` retrieves several entities at once
 - Test units for the changes of this release, TestPlugin routes that run maintenance, settle reputation and check the
   migration of a populated 1.0.14 database (`TEST_PLUGIN_DATABASE_ROOT_PASSWORD`), and the `InMemoryConfiguration`
   test helper, a ConfigLib configuration that never touches a file

### Changed
 - Reputation is rebuilt around daily activity, replacing reputation windows. Scans by authenticated clients record
   the activity of the author and of the entities mentioned in the content per day (UTC), buffered in Redis when it
   is enabled, and `federationlib reputation settle` applies every completed day, at most one change per entity per
   day: authors gain `scanning.reputation_gain` (now 10) for a day of normal content, nothing for a day with content
   that warranted caution, and lose `scanning.reputation_loss` (-5) for a day with content that reached the block
   threshold; mentioned entities (eg; youtube.com) gain for a day on which `scanning.mention_min_authors` (3)
   distinct authors mentioned them in normal content, and never lose from mentions. Gains credit the parent of a
   `CHILD` entity and taper above 500. A stored `reputation_gain` still at the former default of 1 becomes 10
 - Blacklists lower the reputation of entities related through `ALTERNATIVE` or `CHILD` only, not `PROXY`
 - The configuration is simplified from 142 to 58 settings. Settings repeated per record type or scanning rule became
   a single list or map, which one environment variable can also set (`ENTITIES,EVIDENCE` or `ENTITY=5000/600`), and
   durations accept units (`30d`, `2y`):
   - `server.public` (list) replaces `server.public_*` and `search.public_search`
   - `server.max_page_size` replaces `server.list_*_max_items`
   - `remote_retrieval` (`uploads`, `scans`, `timeout`, `allow_private`) replaces `scanning.url_retrieval`
   - `scanning.modifiers` (map) replaces the 23 `scanning.modifier_*`; `CLASSIFICATION_NORMAL` now defaults to `10.0`
     instead of `1.0`, as much as a `SUSPICIOUS` classification takes away, so a confident `NORMAL` classification of
     one plugin can offset an unsure spam classification of another
   - `scanning.auto_report` is `block`, `caution` or `off`, replacing `auto_report_threshold` and `auto_report_caution`
   - `scanning.report_reputation` and `scanning.report_named_entity_reputation` (maps, the latter `false` to disable)
     replace their `_normal`, `_suspicious` and `_malicious` settings
   - `scanning.dns_lookup_limit` of `0` disables DNS lookups, replacing `scanning.dns_lookups`
   - `search.types` (list) replaces `search.enabled` and `search.enable_*`
   - `maintenance.retention` (map) replaces `maintenance.clean_*` and `clean_*_ttl`
   - `redis.record_cache` (map) replaces `redis.<type>_cache_enabled`, `_limit` and `_ttl`
   - `scanning.collects_public_scan_content` is replaced by the `collects_scan_content` property plugins declare
   - Removed as their values are fixed: `scanning.risk_score_neutral_point` (50), `risk_score_min_bound`/`max_bound`
     (0 and 100), `reputation_min_bound`/`max_bound` (-1000 and 1000) and `database.charset`/`collation` (utf8mb4);
     `scanning.reputation_window_duration` is removed with reputation windows

   Keys stored in the configuration file are converted and removed from it, deprecated environment variables are
   converted on every start
 - `PATCH /operators/{uuid}/permissions` and `PATCH /operators/{uuid}/configuration` replace the `client-permissions`,
   `management-permissions`, `operator-permissions` and `auto-assign` endpoints, which the client's `set*Permissions()`
   and `setAutoAssign()` (setting both opt-ins) now use. `OperatorRecord::getConfiguration()` returns null when the
   server did not disclose the configuration
 - Automatic report assignment follows the kind of report: automated reports go to operators that opt in to automated
   reports, others to operators that opt in to ordinary reports, and disabled operators are never assigned. The
   previous `auto_assign` opt-in carries over to both
 - The command-line interface follows the style of docker: `operators ls`, `operators create <name>`,
   `operators <uuid> [inspect|set|enable|disable|token|rm]`, `audit ls` and `bayesian sync` replace `list-operators`,
   `create-operator`, `get-operator`, `edit-operator`, `generate-operator-access-token`, `delete-operator`,
   `list-audit` and `bayesian-sync`. `operators <uuid> set` changes permissions and configuration (`--client`,
   `--management`, `--operator`, `--bot`, `--auto-assign-reports`, `--auto-assign-automated-reports`), the options
   of `audit ls` (`--page`, `--limit`, `--type`) now apply, and the system operator can no longer be modified, like
   the root operator
 - The report supporting a blacklist record may concern another entity than the one being blacklisted (eg;
   blacklisting the domain a reported account advertised) instead of being rejected with HTTP 404, and
   `FederationClient::blacklistEntity()` no longer requires a report
 - Classify Evidence replaces the classification of evidence that is already classified (OFD 2.0) instead of
   rejecting the request with HTTP 409, audited as a reclassification and producing `EVIDENCE_CLASSIFIED` again.
   Classifying with the same classification changes nothing, and Close Report still never replaces classifications
 - The bundled BayesianServer (requires 1.0.4) runs the logistic model with chat placeholders, without stop-words,
   with deduplicated learning, `2^16` hash buckets per feature block (as accurate as `2^18` with a quarter of the
   memory) and thresholds calibrated for a 5% false positive rate on `NORMAL` content, and keeps a data log of
   classified messages to label real traffic. The previous model is replaced on the next start by one trained from
   the classified evidence
 - Rebuilding a broken Bayesian model trains the new model with the backed up archive (everything the previous model
   learned, including since deleted evidence) before the classified evidence
 - Ordering values for `by` are uppercase (e.g. `CREATED`) as defined by OFD 2.0 and the client sends them uppercase,
   the server still accepts them case-insensitively
 - A token presented to a public method no longer causes an error when it is malformed, unknown or belongs to a
   disabled operator, the request is processed as unauthenticated. A valid token still authenticates the operator
 - Scan Content only requires client permissions when content scanning is not public, scans by operators without
   client permissions do not contribute to reputation
 - List Operator Audit Logs returns the public entries of another operator to an authenticated operator without
   operator permissions when audit logs are public, instead of rejecting the request with HTTP 403
 - Path segments are percent-decoded once before routing and validation, so entity addresses can be percent-encoded
   in paths (e.g. `user%40example.com`), and the client percent-encodes entity identifiers in every entity path
 - Without a matching `Origin`, `Access-Control-Allow-Origin` is the origin of the base URL instead of the full URL
 - Delete Operator rejects an operator deleting itself with HTTP 400
 - Set Entity Relationship rejects a relationship of an entity to itself with HTTP 400
 - Push Entity rejects a local part (`id`) that is not canonical with HTTP 400, and an entity host with leading or
   trailing whitespace is no longer accepted
 - The cross-collection Search returns file attachments only to operators with management permissions, as Search
   Attachments does, and returns records of non-public domains to authenticated operators
 - `FileAttachmentRecord::getEvidenceUuid()` returns null instead of an empty string when a server reports no
   associated evidence record
 - Cache layer performance for `/scan` and entity queries, with Redis enabled:
   - The active blacklist records of entities are cached in the blacklist search namespace, for no longer than until
     the first of them expires, instead of being queried for every resolved entity and its parent
   - Entity hashes that have no entity are cached for `redis.search_cache_ttl`, so names in scanned content that are
     not entities are not queried again until an entity is registered
   - The entities related to an entity are cached in the entity search namespace
   - The public addresses of the DNS names resolved by `/scan`, including names that do not resolve, are reused for
     `redis.search_cache_ttl` (OFD "Scan Content" permits reusing an earlier resolution)
   - `EntitiesManager::getEntitiesByUuids()` reads and writes cached entities in pipelined round trips
     (`RedisConnection::getRecords()` and `setPointers()`)
 - The authenticated operator is resolved once per request rather than by every check that needs it (entity metadata,
   telephone entities, extended search)
 - Removing a cached operator reads only its Access Token and deletes the record and its Access Token pointer in a
   single command, and an Access Token pointer whose cached operator holds a different Access Token is ignored

### Removed
 - Unused code: the `UploadHandler` class (attachment uploads are handled by Upload Attachment),
   `EvidenceManager::getClassifiedEvidence()` (replaced by `getClassifiedSince()`) and
   `RedisConnection::getRecordsByField()`

### Fixed
 - Form-encoded and multipart bodies of PATCH, PUT and DELETE requests are read, PHP only parses POST bodies, so their
   parameters were ignored; this also makes direct uploads with `PUT /attachments` work
 - Update Evidence Confidentiality rejects a missing or non-boolean `confidential` member with HTTP 400 instead of
   making the evidence non-confidential, rejects making evidence linked to an `ILLEGAL_CONTENT` report non-confidential
   with HTTP 409, and records an `EVIDENCE_UPDATED` audit log entry
 - Deleting evidence, an entity or an operator deletes the stored files of the attachments the database deletes with
   them, which were left on disk
 - Deleting an operator that created evidence, reports or blacklist records, which are deleted with it, requires
   management permissions besides operator permissions
 - Upload Attachment attaches files only to evidence the operator submitted, unless it holds management permissions
 - Download Attachment, Get Attachment Info and Delete Attachment treat an attachment without evidence as an
   attachment of confidential evidence instead of failing or exposing it, and Delete Attachment deletes it
 - Close Report rejects `blacklist_incident_type` for a report that concerns no entity instead of ignoring it, Assign
   Operator to Report rejects closed reports and malformed operator UUIDs with HTTP 400, and Extend Blacklist rejects
   expired records and non-integer `seconds`
 - Query Entity omits the blacklist records of hidden telephone entities from `active_blacklists`
 - Entity hosts under `e164.arpa`, such as the ENUM domain of a number (eg; `6.5.4.3.2.1.5.5.5.1.e164.arpa`), are
   rejected with HTTP 400; telephone entities remain `<number>@e164.arpa`. Maintenance skips such hosts stored before
   they were rejected instead of failing while linking subdomain entities
 - Request parameters of the wrong type, such as a JSON array or number where a string is expected, are rejected with
   HTTP 400 instead of failing with HTTP 500; unrecognized `category`, `by` and `order` values are still ignored
 - Integer parameters (`limit`, `page`, `expires`, `blacklist_expires`, `seconds`, `top_k`) only accept whole numbers,
   values such as `1.5` or `10abc` are rejected with HTTP 400 instead of being cast, and `threshold` must be a number;
   `blacklist_expires` is only validated when a blacklist record is created
 - Boolean parameters and the `confidential` member of evidence only accept `true`, `false`, `1` and `0`, any other
   value is rejected with HTTP 400 instead of being read as false, which could make evidence non-confidential
 - An empty `text_content` in evidence is rejected with HTTP 400 instead of being treated as absent, and Submit
   Evidence rejects members its body does not define
 - Clear Reputation, Set Entity Relationship, Clear Entity Relationship and Delete Blacklist record the affected entity
   in their audit log entries, so they appear in the entity's audit log
 - Create Operator and Update Operator Name respond with HTTP 409 instead of HTTP 500 when a concurrent request takes
   the name after it was checked
 - Scan Content rejects an `author` that is not a string with HTTP 400 instead of failing with HTTP 500, and an
   `author` that names the `e164.arpa` namespace without being a canonical telephone entity (eg; `+15550123456@e164.arpa`,
   `15550123456@E164.ARPA` or `e164.arpa`) with HTTP 400 instead of leaving it unresolved
 - A path segment that contains `/` once percent-decoded is rejected with HTTP 400 instead of being matched as two
   segments, the `Bearer` authentication scheme is matched case-insensitively, and error responses no longer escape
   slashes
 - Blacklist and report records with an incident type the client does not recognize are read as `OTHER` instead of
   failing
 - The maintenance command removed records still in use, it now removes only expired and lifted blacklist records
   (permanent ones were removed once older than the TTL), closed reports by when they were last changed (open reports
   were removed too), and entities without evidence, reports or blacklist records that haven't changed within the TTL
   and aren't whitelisted (every entity created before the TTL was removed along with its possibly recent records)
 - The maintenance command removes records in batches, archiving each batch (`--archive`) before it's removed, and
   keeps a batch that can't be archived. Records were removed even if archiving them failed, records that became old
   while archiving were removed without being archived, and records of the same table could be archived with
   different columns
 - The maintenance command removes the stored files of the attachments it removes and clears the cached records that
   referred to removed records, exits with `0` when maintenance is disabled, runs the remaining tasks when one fails
   and rejects `--archive` without a directory
 - Scan Content considered expired temporary blacklist records when suggesting to block the author entity, only
   active blacklist records are considered now
 - The `LIFTED` category of List Blacklist returned nothing unless `include_lifted` was set
 - Update Operator Name accepted the reserved names `root` and `system`, they are now rejected case-insensitively as
   Create Operator does
 - `ServerInformation` parsed by the client always reported the server name as `Federation Server` and the
   `api_version` as the client's own version instead of the values returned by the server
 - Search methods accepted a query of a single multibyte character, the query must contain at least two
   non-whitespace characters, and a non-string `q` or `type` parameter caused an internal error instead of HTTP 400
 - Close Report rejected a `blacklist_expires` in the past even without `blacklist_incident_type`, it is now ignored
 - Query Entity omitted the entities referencing the queried entity when the queried entity has a relationship
 - Only 64 lowercase hexadecimal characters are resolved as a SHA-256 identifier, any other value as an entity address



## [1.0.16] - 2026-10-08

This update introduces a fix to the docker image

### Fixed
 - The storage directory (`FEDERATION_STORAGE_PATH`) is now owned by `www-data` on every container start, uploads
   failed with "Storage directory is not writable" when it was a bind mounted host directory owned by root



## [1.0.15] - 2026-10-07

This update introduces a new recovery feature for production environments and a logging fix.

### Added
 - `federationlib init` checks the Bayesian model when BayesianPlugin is enabled, a broken model is backed up and
   rebuilt from the classified evidence records (configured with `bayesian_model`)
 - `bayesian_recovery/` scripts to start and stop BayesianServer temporarily during `federationlib init`

### Changed
 - Client request errors (4xx) are now logged as debug events instead of errors, only server request errors (5xx)
   are logged as errors
 - BayesianServer's model moved to `/var/www/bayesian_model/model`, existing volumes are migrated on the first start



## [1.0.14] - 2026-10-06

This update introduces the new plugin system, the removal of the hard-coded Bayesian scanning implementation in-favor
for a separate plugin entirely called [BayesianPlugin](https://github.com/nosial/BayesianPlugin) instead. FederationLib's
functionality remains the same with the included BayesianServer functionality, but developers can now extend FederationLib's
capabilities and accuracy with the use of custom plugins.

### Added
 - Plugin system: plugins are ncc packages that can add new routes, hook into existing routes (`PRE_REQUEST`,
   `POST_REQUEST`, `OVERRIDE`) and react to events (`AUDIT_LOG`, `CONTENT_SCAN`, `QUERY_ENTITY`, `RECORD_CHANGE`),
   configured with the `plugins` option (`FEDERATION_PLUGINS`). See `PLUGINS.md` for details
 - Plugin routes can use the non-standard `PUSH` request method, eg; to proxy services that use it like BayesianServer
 - `federationlib init` validates every configured plugin and fails if any is misconfigured
 - Docker-only `REQUIRE_PLUGINS` environment variable to install or update plugin packages on startup
 - Docker-only `AUTOSTART` environment variable, the path to a shell script executed on every container start before
   plugins are installed and FederationLib is initialized (eg; to set up services or dependencies required by plugins)
 - The docker image installs BayesianPlugin (`nosial/BayesianPlugin@github`) and always enables it as the first plugin
   in `FEDERATION_PLUGINS`
 - `tests/TestPlugin` with `Dockerfile.test`, `docker-compose.test.yml` and new Makefile targets; tests and CI now run
   against `Dockerfile.test`
 - Extended search for authenticated operators: searches also match entity metadata and related entity, evidence
   notes, report and operator UUIDs, incident types, attachment MIME types and the related records of audit log
   entries. These columns are not indexed, so unauthenticated requests never use the extended search, and hosts can
   disable it with `search.extended_search` (`FEDERATION_SEARCH_EXTENDED`)
   ([#10](https://github.com/nosial/FederationLib/issues/10))

### Changed
 - Content scans and entity queries can include results from plugins (extra scanning rules, classifications and
   suggested actions)
 - Deleting an entity or operator now also clears the related cached records (evidence, reports and blacklist records)
 - `RequestHandler` ignores and logs responses written after a response was already sent, instead of sending a second
   JSON document

### Fixed
 - Whitelisted entities no longer have their reputation adjusted by blacklists (of the entity or a related entity),
   report classifications or reputation windows ([#8](https://github.com/nosial/FederationLib/issues/8))
 - Registering a domain entity (eg; `example.com`) now links its existing unlinked subdomain host entities
   (eg; `foo.example.com`) to it as a `CHILD`, matching the behavior when the domain is registered first.
   `federationlib init` links the unlinked subdomain entities of existing databases, registering their domain entity
   when needed ([#9](https://github.com/nosial/FederationLib/issues/9))
 - Deleting an entity now also clears the cached records of entities that had a relationship with it

### Removed
 - The built-in Bayesian implementation and its `bayesian` configuration (`FEDERATION_BS_*`), now provided by
   BayesianPlugin. The docker image still bundles BayesianServer

   
## [1.0.13] - 2026-10-01

This update follows version `v1.0-R4` of the OFD Specification.

### Changed
 - `PATCH /entities/{identifier}/relationship` (Set Entity Relationship) and `DELETE /entities/{identifier}/relationship`
   (Clear Entity Relationship) now require client permissions instead of operator permissions. Operators with
   management permissions inherit them, while operators holding only operator permissions are now rejected with
   HTTP 403.



## [1.0.12] - 2026-10-01

This update introduces changes in accordance to the specification, improvements to reputation effects and includes a
bug fix

### Added
 - Added the `database_metadata` table, a key-value store for information about the database itself, and record the
   database schema version in it under the `version` key, starting at `1.0` (#7). `federationlib init` creates the
   table when it is missing and, in future releases, runs each schema migration newer than the recorded version in
   order (registered in `DatabaseConnection::getMigrations()`). Initialization fails if the database's schema version
   is newer than the release supports.
 - Added the `server.allow_illegal_content` configuration option (`FEDERATION_ALLOW_ILLEGAL_CONTENT`, default `true`).
   When disabled, `POST /reports` rejects `ILLEGAL_CONTENT` reports with HTTP 403. The setting is published as
   `allow_illegal_content` in the server information object.
 - Added configurable reputation adjustments for closing a report with a classification flag:
   `scanning.report_reputation_normal` (default `1`), `scanning.report_reputation_suspicious` (default `-10`) and
   `scanning.report_reputation_malicious` (default `-20`).
 - Closing a classified report now also adjusts the reputation of existing entities mentioned in the report's evidence,
   resolved with the same named entity extraction as content scanning. Controlled by
   `scanning.report_named_entity_reputation` (default `true`) and the
   `scanning.report_named_entity_reputation_{normal,suspicious,malicious}` deltas (defaults `1`, `-5`, `-10`).
 - Registering a subdomain host entity (e.g. `sub1.example.com`) now automatically registers its registrable domain
   (`example.com`) when missing and sets the subdomain's relationship to it as `CHILD`. Registrable domains are
   determined with the bundled [Public Suffix List](https://publicsuffix.org/) (`Resources/public_suffix_list.dat`),
   exposed as `Utilities::getRegistrableDomain()`, so `foo.example.co.uk` is linked to `example.co.uk`. This is a
   FederationLib feature built on standard OFD entity relationships and is controlled by the
   `server.link_subdomain_entities` configuration option (`FEDERATION_LINK_SUBDOMAIN_ENTITIES`, default `true`).
 - Added the `scanning.auto_report_caution` configuration option (`FEDERATION_SCANNING_AUTO_REPORT_CAUTION`, default
   `false`). When enabled, content scans with a `CAUTION` suggested action also generate an automated report. It is
   independent of `scanning.auto_report`, and a scan that meets both conditions still produces only one report (#3).
 - Blacklisting an entity now lowers its reputation by `scanning.blacklist_reputation` (default `-50`), and lowers the
   reputation of each directly related entity (its relationship target and the entities that reference it) by
   `scanning.blacklist_related_reputation` (default `-10`). Applies to blacklists created with `POST /blacklist` and when
   closing a report; setting either option to `0` disables that adjustment (#2).

### Removed
 - Removed the unused `redis.system_caching_enabled` configuration option (`FEDERATION_SYSTEM_CACHING_ENABLED`); the
   server information cache it controlled was removed previously.

### Changed
 - Evidence created with an `ILLEGAL_CONTENT` report, or linked to one via `PATCH /evidence/{uuid}/link-report`, is
   now always marked as confidential regardless of the submitted `confidential` value.
 - Closing a report as `SUSPICIOUS` now lowers the reported entity's reputation (previously no effect), and `MALICIOUS`
   lowers it by 20 instead of 1 by default.
 - Named entity resolution used by content scanning moved to `EntitiesManager::resolveNamedEntity()` so it can be
   shared with report closing.
 - Reports are now distributed evenly among auto-assignable operators in a round-robin order when the Redis cache layer
   is enabled, using a shared atomic counter so the rotation holds across all server workers. Without the cache layer,
   or if it fails, an eligible operator is still selected at random. `OperatorManager::getRandomAutoAssignOperator()`
   was replaced by `OperatorManager::getNextAutoAssignOperator()` (#5).
 - Automated reports generated by content scanning are no longer assigned an operator twice; the assignment made when
   the report is created is kept.

### Fixed
 - A leading `www.` label is now stripped from DNS entity hosts, so `www.example.com` and `example.com` resolve to
   the same entity instead of two separate ones. The label is kept when the remainder is a public suffix (`www.com`,
   `www.co.uk`), and other subdomains are unaffected. Applied when registering entities and when deriving the SHA-256
   identifier via `Utilities::canonicalizeHost()` (#4). Repeated labels are stripped too, so `www.www.example.com`
   becomes `example.com` rather than `www.example.com`, which previously left the stored host and its identifier
   out of sync.
 - `federationlib init` now migrates entities registered before host canonicalization (#6). An entity whose host has a
   leading `www.` label is renamed to its canonical host, keeping its UUID and everything linked to it. If an entity
   for the canonical host already exists, the reports, evidence, blacklist records, audit log entries, relationships
   and reputation of the `www.` entity are moved to it and the `www.` entity is removed. Each change is recorded in
   the audit log under the system operator. Once no `www.` hosts remain, the migration has no effect.


## [1.0.11] - 2026-09-29

This update also updated in accordance to version `v1.0-R2` of the OFD Specification and includes bug fixes & improvements.

### Changed
 - Operator records are now public information: `GET /operators`, `GET /operators/{uuid}` and `GET /operators/search`
   no longer require authentication or operator permissions, and the cross-collection search returns operator records to unauthenticated requests.
 - `public_search_types` in the server information now includes `OPERATOR` whenever operator search is enabled.

### Fixed
 - The generated OpenAPI specification referenced `#/components/schemas/SearchResult` without defining it, which
   caused specification parsers to fail with `EMISSINGPOINTER`; `SearchResult` is now a component schema.
 - `POST /attachments` and `PUT /attachments` no longer share the duplicate operationId `uploadAttachment`; the `PUT`
   operation is now `uploadAttachmentPut`.
 - Object and request body schemas no longer use the `nullable` keyword, which is invalid in OpenAPI 3.1 and later;
   nullable members are now declared as `"type": [..., "null"]`, or `anyOf` with a `null` type for references.
 - Entity and evidence metadata schemas now declare the flat object of scalar values defined by the specification,
   and the Update Entity request body no longer describes its metadata as merged, since it replaces the existing metadata.
 - The `ScannedContent` schema was missing the `suggested_action`, `suggested_lift_timestamp`, `scan_results` and
   `risk_score` members.
 - The `OperatorRecord` schema no longer declares an `access_token` member, which is not part of an operator record.
 - `GET /search` serialized operator records with their `access_token` member (`null`, or the stored token hash for
   requesters with operator permissions); search results now use the standard record representation, which omits it.
 - `EntityRecord.metadata` was serialized as a JSON-encoded string rather than a JSON object; it is now an object, or
   null when the entity has no metadata.
 - `BlacklistRecord.expires` was omitted from serialized blacklist records when the blacklist is permanent; it is now
   always present and null for permanent blacklists.
 - `SearchResult::fromArray()` read the record type from `record_type` instead of `type`.



## [1.0.10] - 2026-09-08

This update introduces improvments to the learning logic & bug fixes.

This update also updated in accordance to version `v1.0-R1` of the OFD Specification.

### Added
 - `scanning.reputation_gain` configuration option (`FEDERATION_SCANNING_REPUTATION_GAIN`, default `1`) and
   `ScanningConfiguration::getReputationGain()`, the reputation granted per clean activity window. The value is clamped
   to `0`-`10` as the OFD specification limits incremental positive adjustments to 10 points; `0` disables gains.
 - `ReportManager::hasOpenReports()` to check whether an entity has opened reports awaiting a conclusion.
 - `EntitiesManager::getEntityByIdentifier()`, which resolves any OFD entity identifier form (UUID, SHA-256 identifier,
   named entity address or host entity address with a DNS, IPv4 or IPv6 entity host), and
   `Utilities::matchEntityPath()` for matching `/entities/{identifier}` routes.

### Changed
 - Content scans no longer decrease reputation. Reputation now increases gradually for sustained normal activity of the
   author entity and its parent: each reputation window grants `scanning.reputation_gain` once, regardless of how many
   scans it received.
 - A reputation window grants nothing if any scan in it was classified as suspicious or malicious, if the entity was
   blacklisted, or if the entity has an opened report awaiting its conclusion. Submitted reports, including automated
   ones, no longer affect reputation until they are closed; decreases only come from closing a report as malicious.
 - Only scans from authenticated clients affect reputation. Anonymous scans still return results and still generate
   automated reports for high risk content, but never change reputation.
 - `scanning.reputation_window_duration` now defaults to `3600` seconds instead of `300`.
 - Cache size limits reuse a key count for up to 10 seconds instead of scanning the whole Redis keyspace on every cache
   miss, so cache misses no longer slow down as Redis grows. Limits may be briefly exceeded by the records cached within
   that window.
 - `RedisConnection::getRecord()` reads a cached record with a single `HGETALL` instead of `EXISTS` followed by
   `HGETALL`, and returns null when Redis is disabled.
 - The Top Threats result set is cached in the entity search namespace, so it is invalidated by any entity change.
 - `ScannedContent::getScanResults()` is computed once per scan instead of on every call.
 - `/entities/{identifier}` routes accept every entity identifier form, following OFD-Specification 1.0-R1: a host
   entity address (e.g. `/entities/example.com`, `/entities/2001:db8::1`) and named entity addresses with an IP entity
   host are now routable. An entity address in a path must be canonical; an invalid identifier is rejected with HTTP
   400. A dynamic segment never matches the literal sub-paths `search` and `top-threats`, whatever the request method.
   A lowercase single-label string such as `not-a-valid-uuid` is a valid host entity address, so an unknown one is now
   answered with HTTP 404 (entity not found) instead of HTTP 400.
 - All entity handlers resolve identifiers through `EntitiesManager::getEntityByIdentifier()` instead of each
   duplicating the UUID/SHA-256/address lookup.

### Removed
 - `scanning.reputation_max_delta`, `scanning.reputation_min_delta` and `scanning.reputation_scaling_factor`
   configuration options and their `ScanningConfiguration` getters, replaced by `scanning.reputation_gain`.

### Fixed
 - An entity could not be resolved by its host entity address alone (issue #1): `GET /entities/example.com` did not
   route, and `entity_identifier`, `reporting_entity` and `target_identifier` request members rejected a bare host
   with HTTP 400, even though the entity was resolvable by its UUID or SHA-256 identifier.
 - `FederationClient::listEntityBlacklistRecords()` was documented as returning `EvidenceRecord[]` instead of
   `BlacklistRecord[]`.
 - `ReportManager::getReport()` and `EvidenceManager::getEvidence()` threw a `TypeError` when the cached record expired
   between the existence check and the read.
 - An entity's own reputation fed back into its next reputation window through the author reputation scan rules, so
   a negative reputation kept decreasing with any activity and a positive one kept increasing.
 - Content classification used BayesianServer's `confidence` field, which is the language detection confidence (always
   `1.0` for the detected language), so every non-normal classification applied its full penalty. It now uses the
   classifier's `top_probability`.
 - Classification penalties are scaled by how far the probability is above chance (1/3 for three labels), so near
   coin-flip classifications of short messages no longer push normal content over the block threshold.
 - `ContentClassification::getReportUuid()` returned `string` for a nullable value, and `fromArray()` required the
   `report_uuid` key.
 - Automated report messages showed rule points and confidence with a misleading `%` suffix; rule points are now shown as
   points and confidence as a percentage.


## [1.0.9] - 2026-09-26

This update includes changes from the specification

### Changed
 - Management permissions now include client permissions, but client permissions do NOT include management
   permissions. `OperatorRecord::hasClientPermissions()` returns `true` for any operator with management
   permissions, so a management-only operator can also perform client-level actions (e.g. `pushEntity`,
   `submitEvidence`, `submitReport`, and entity metadata updates), while
   `OperatorRecord::hasManagementPermissions()` remains `true` only when management permissions were explicitly
   granted (client-only operators are not promoted to managers). Operator permissions remain separate and are not
   inherited.
 - Updated `SetRelationship` terminology from "target entity" to "related entity" in error messages, parameter
   descriptions, and the OpenAPI schema; `FederationClient::setEntityRelationship()` parameter renamed from
   `targetIdentifier` to `relatedEntityIdentifier`



## [1.0.8] - 2026-08-23

This update changes how blacklist records reference the report record rather than a singular evidence record as
per the specification change.

### Changed
 - Blacklist records now reference a report instead of a single evidence record, allowing the supporting material of a
   blacklist to span every evidence record attached to that report. The `blacklist.evidence` column was replaced by
   `blacklist.report` (foreign key to `reports`, cleared on report deletion), `POST /blacklist` now accepts
   `report_uuid` instead of `evidence_uuid`, `BlacklistRecord` exposes `report` / `getReportUuid()` instead of
   `evidence` / `getEvidenceUuid()`, and `FederationClient::blacklistEntity()` takes the supporting report UUID. A
   supplied report must exist and must belong to the entity being blacklisted.
 - Closing a report with a blacklist action now links the created blacklist record to the closed report.
 - Deleting or pruning a report no longer removes blacklist records: the blacklist stays in effect and only loses its
   report reference, where deleting evidence previously deleted the blacklist records it backed.



## [1.0.7] - 2026-08-17

This update introduces a minor change

### Added
 - Added public-access capability metadata to `ServerInformation`: entity metadata, content scanning, entity querying,
   global search availability, and enabled/public dedicated search record types.



## [1.0.6] - 2026-08-17

This update introduces a minor change

### Changed
 - Automated reports now omit scan-rule entries with a zero percentage in the generated message, retaining only rules
   that affected the calculated result.



## [1.0.5] - 2026-08-16

This update corrects automated scan-report associations and improves fresh-database query performance and Redis cache throughput.

### Changed
 - Optimized fresh database schemas for report, evidence, and audit-log timelines. Replaced redundant report UUID
   indexes with ordered composite indexes aligned with manager filtering and pagination; report-evidence reads now
   index confidentiality and ordering; audit relation/type timelines include UUID tie-breakers.
 - Updated `RedisConnection` search-result invalidation to use atomic namespace generations instead of scanning and
   deleting every cached result key. Search keys now include a SHA-256 parameter digest and the current namespace
   generation.
 - Batched Redis record writes and reverse-dependency invalidation with pipelines. Manager pre-cache population now
   writes record hashes and TTLs in a single pipeline per result set.
 - Added cache-through result caching for entity-scoped evidence, reports, blacklist records, and audit-log
   timelines. Each result set is invalidated through its manager namespace after a successful mutation.

### Fixed
 - Fixed automatically generated scan reports omitting their reporting entity. Automated reports now reference the
   resolved author entity and retain their generated evidence records through the report association, matching
   manually submitted reports.



## [1.0.4] - 2026-08-15

This update adds relationship-aware entity queries, configurable scan score modifiers, and safeguards against PHP
diagnostics, timing attacks, and unsafe attachment filenames in web responses.

### Added
 - Added `QueryEntity` for `GET /entities/{identifier}/query` and
   `FederationClient::queryEntity()`. Queries accept UUID, SHA-256 hash, and entity-address identifiers and return
   the target entity with its direct relationship group.
 - Added `EntityQueryResult`, containing `entity_record`, `related_entities`, `active_blacklists`,
   `suggested_action`, and `suggested_lift_timestamp`.
 - Added `server.public_query_entity` (`FEDERATION_PUBLIC_QUERY_ENTITY`, default `true`) to control whether
   unauthenticated clients may query entity relationship groups.
 - Added `EntitiesManager::getEntitiesByRelationshipEntity()` and
   `BlacklistManager::getActiveEntriesByEntities()` for resolving a relationship group and its non-lifted,
   non-expired blacklist records.
 - Added entity-query coverage for relationship grouping, UUID/hash/address lookup, blacklist action precedence,
   public access, self-references, unknown identifiers, and lifted-blacklist exclusion.

### Changed
 - Updated the production PHP configuration to disable displayed startup and runtime errors. PHP diagnostics remain
   available to registered handlers, including LogLib2, without being included in HTTP responses.
 - Updated the OpenAPI component registry and request schemas: `ContentInput` is now a reusable schema used by
   `SubmitEvidence`, `SubmitReport`, and `ScanContent`; `EntityQueryResult` is included in generated schemas.
 - Consolidated audit mutation events. Entity reputation and whitelist changes now use `ENTITY_UPDATED`; operator
   permission, access-token, name, auto-assign, and CLI edit changes now use `OPERATOR_UPDATED`.
 - Renamed all scan score modifier configuration keys to `scanning.modifier_*` (and their
   `FEDERATION_SCANNING_MODIFIER_*` environment variables). Each author, named-entity, parent, and classification
   modifier is configurable through `ScanningConfiguration`.
 - Renamed `ScanningConfiguration` modifier accessors to `getModifier*()` and removed unused legacy scoring
   configuration accessors.
 - Renamed the `BLACKLIST_RECORD_DELETED` audit log type to `BLACKLIST_DELETED`.

### Fixed
 - Sanitized the server-supplied filename used by `FederationClient::downloadAttachment()`. Directory components
   are stripped for both slash styles, and empty or relative-only names fall back to the attachment UUID.
 - Replaced the master access-token comparison with `hash_equals()` to prevent timing-based token disclosure.

### Removed
 - Removed superseded audit log types `ENTITY_REPUTATION_CLEARED`, `ENTITY_WHITELIST_CHANGED`,
   `OPERATOR_PERMISSIONS_CHANGED`, `OPERATOR_ACCESS_TOKEN_GENERATED`, `OPERATOR_NAME_CHANGED`, and
   `OPERATOR_AUTO_ASSIGN_CHANGED`.
 - Removed the unused `BLACKLIST_ATTACHMENT_ADDED` audit log type.



## [1.0.3] - 2026-08-13

This update introduces updates as per specification and some minor improvements

### Added
 - Added `ClassifyEvidence` request handler for `PATCH /evidence/{uuid}/classify`. It requires management
   permissions, assigns an evidence classification exactly once, returns `409 Conflict` for repeat attempts,
   and submits textual evidence to Bayesian training when enabled.
 - Added optional `classification` to `SubmitEvidence` and `FederationClient::submitEvidence()`. A
   management operator can classify evidence at creation time and supply Bayesian training data without a
   separate request.
 - Added classification immutability, report-close skip, submission-classification, and authorization coverage
   to the evidence test suites.

### Changed
 - Updated `EvidenceManager::addEvidence()` to persist an optional classification in the initial evidence
   insert. `updateClassificationFlag()` now uses a conditional update so concurrent classification requests
   cannot replace an existing value.
 - Updated `CloseReport` to classify and train only previously unclassified evidence records. Report closure
   now still assigns classifications when Bayesian training is disabled or unavailable.

### Fixed
 - Fixed `SubmitReport` overwriting the operator selected by auto-assignment with the submitting operator.
   Reports now retain the eligible management operator selected by `ReportManager::createReport()`.



## [1.0.2] - 2026-08-12

This update introduces new features and changes

### Added
 - Added `ContentInput` object (text content, note, tag, confidential, metadata) to be used as input for
   content-based evidence in report submission and scanning
 - Added `scanning.action_block_threshold` and `scanning.action_caution_threshold` configuration values
   (`FEDERATION_SCANNING_ACTION_BLOCK_THRESHOLD`, default `80.00`, and `FEDERATION_SCANNING_ACTION_CAUTION_THRESHOLD`,
   default `60.00`) which replace the hardcoded risk score thresholds in `ScannedContent::getSuggestedAction()`

### Changed
 - Updated `SubmitReport` and `ScanContent` to remove file attachment handling and accept evidence via
   `ContentInput` (or a raw array) instead; `FederationClient::submitReport()` and
   `FederationClient::scanContent()` signatures were updated accordingly (`localFilePaths`/`remoteUrls`
   parameters removed)
 - Updated `ReportSubmission` to remove the `attachments` property and make `evidence` an array of
   `EvidenceRecord` objects as per specification
 - Updated `ScannedContent` to support multiple `ContentClassification` results: `getClassifications()` returns
   all classification results while `getClassification()` returns an aggregate (worst classification flag with
   average confidence)
 - Updated `ReportManager::createReport()` to automatically assign a randomly selected operator with
   `auto_assign` enabled when one is available
 - Updated `SetRelationship` to accept any entity identifier for the target entity (UUID, SHA-256 hash, or
   entity address) via the `target_identifier` parameter; `FederationClient::setEntityRelationship()` parameter
   renamed from `targetEntityUuid` to `targetIdentifier`
 - Altered the risk score calculation (experimental): adjusted `ScanningRules` point values (for example
   `AUTHOR_GOOD_REPUTATION` from `1.5` to `20.0` and `CLASSIFICATION_MALICIOUS` from `-0.4` to `-25.0`) and
   scaled reputation contributions by a factor based on the configured reputation bounds
 - Updated `TestHelpers` and existing test suites for the `ContentInput` based submissions and
   multi-classification support

### Fixed
 - Fixed a race condition in `EntitiesManager::registerEntity()` that caused concurrent `POST /entities` requests
   for the same entity to fail with a 500 `Unable to register entity` error (duplicate key violation on
   `entities_hash_uindex`). The manager now handles the duplicate-key failure internally, merges the provided
   metadata into the existing record, and returns the existing entity UUID, making entity registration idempotent



## [1.0.1] - 2026-08-10

This update introduces operator auto-assignment support and the ability to retrieve evidence records
associated with a report.

### Added
 - Added `auto_assign` flag to operator records (`operators.auto_assign` column, `OperatorRecord` property,
   and OpenAPI schema) so operators can opt-in to automatic report assignment
 - Added `ManageAutoAssign` request handler for `PATCH /operators/{uuid}/auto-assign` to enable or disable
   automatic report assignment; requires operator management permissions and is blocked for built-in operators
 - Added `OPERATOR_AUTO_ASSIGN_CHANGED` audit log type for auto-assignment changes
 - Added `OperatorManager::setAutoAssign()` and `OperatorManager::getRandomAutoAssignOperator()` for managing
   and selecting auto-assignable operators
 - Added `FederationClient::setAutoAssign()` client method for toggling an operator's auto-assign status
 - Added `GetReportEvidenceRecords` request handler for `GET /reports/{uuid}/evidence` to list paginated
   evidence records linked to a report, with optional `include_confidential`, `category`, `by`, and `order`
   parameters
 - Added `EvidenceManager::getEvidenceByReport()` for paginated evidence retrieval by report UUID with
   confidentiality, category, and sorting support
 - Added `FederationClient::listReportEvidenceRecords()` client method for fetching a report's evidence records
 - Added `ReportsEvidenceTest` and `AutoAssignTest` test suites, and updated existing tests for the new features

### Changed
 - Updated `ScanContent` to automatically assign generated reports to a randomly selected operator with
   `auto_assign` enabled and management permissions; skips report creation when no eligible operator exists
 - Updated the default `scanning.auto_report_threshold` from `30.0` to `80.0` (and aligned the
   `ScanningConfiguration` fallback default to `80.00`) to reduce unintended auto-reporting



## [1.0.0] - 2026-08-07

First stable release of FederationLib

### Added
 - Added `server.public_entity_metadata` configuration option (`FEDERATION_PUBLIC_ENTITY_METADATA`, default `false`)
   to control whether entity metadata is exposed to unauthenticated users
 - Added entity metadata visibility helpers to `RequestHandler` (`shouldOmitEntityMetadata`, `entityToArray`,
   `searchResultToArray`, `omitEmbeddedEntityMetadata`) and applied them to `GetEntityRecord`, `ListEntities`,
   `SearchEntities`, `TopThreats`, `ScanContent` and the global `Search` endpoint
 - Added test units covering entity metadata visibility in `EntitiesTest`
 - Added `UPLOAD_ATTACHMENT_PUT` method and `PUT /attachments` route for uploading attachments
 - Added a `Search` tag group to the OpenAPI specification generator
 - Added missing `401`, `403`, `404` and `409` response definitions, `enum` constraints (`IncidentType`,
   `ClassificationFlag`, relationship types, audit log categories, sort order) and parameters to the handler
   specifications
 - Added a `multipart/form-data` request body schema to `SubmitReport`
 - Added a new method `GenerateAccessToken` that allows **ANY** authenticated operator to generate their own access
   token for their own operator access.

### Changed
 - Changed specification version from `2025.01` to `1.0`
 - Operator Access Tokens are now stored securely: the database only persists the SHA-256 hash of each token
   (`operators.access_token` widened to `varchar(64)`, with the `none` sentinel kept literal), and the raw token is only
   returned when it is generated or refreshed via `GenerateOperatorAccessToken`
 - Changed the token refresh route from `/operators/refresh` to `/operators/{uuid}/refresh`, making the operator UUID a
   required path parameter
 - Updated `GenerateOperatorAccessToken` (plus `CloseReport`, `BlacklistEntity`, `SetRelationship`
   `ListOperatorAuditLogs`, `CreateOperator`, `GetSelfOperator`, `SubmitReport` and other handlers) to finalize OpenAPI
   request/response specifications
 - Updated `docker-entrypoint.sh` to print an ASCII banner and enable CLI logging during the initialization step
 - Cleaned up `RequestHandler` (unused import removal, documentation, syntax cleanup)
 - `CreateOperator` now returns a new `CreatedOperator` object that contains the properties `uuid` and `access_token`
   instead of just returning the newly created operator's UUID
 - Updated `EntityRelationshipType` value to uppercase

### Removed
 - Removed all references to `access_token` in the operator record result returned in the HTTP interface, the `access_token`
   property is not part of the `Operator` object as per the specification.



## [0.0.14] - 2026-07-30

This update introduces a new request handler for listing opened reports and sorting support for assigned operator reports

### Added
 - Added `ListOpenedReports` request handler for `GET /reports/opened` with optional `by` and `order` sorting parameters
 - Added `listOpenedReports` method to `FederationClient` with pagination and sorting parameters
 - Added `by` and `order` filtering parameters to `ReportManager::getReportsByAssignedOperator` using `buildReportSortClause`
 - Added test units for the new assigned opened reports listing method

### Changed
 - Updated `FederationClient::submitReport` to allow multiple file attachments to be uploaded when submitting a report
   via `localFilePaths` and `remoteUrls` array parameters



## [0.0.13] - 2026-07-22

This update introduces a new request handler for whitelisting entities

### Added 
 - Added the ability to toggle the whitelist state for an entity


## [0.0.12] - 2026-07-22

This update introduces a new request handler and a few bug fixes

### Added 
 - Added request handler for `/entities/{identifier}` to update an existing entities metadata directly

### Changed
 - Refactored the entire test suite so that the codebase is more maintainable and organized, no tests were
   removed during this change.

### Fixed
 - Fixed entity metadata logic, when using `pushEntity` the metadata is merged, when updating an existing entity the
   metadata is instead replaced entirely.



## [0.0.11] - 2026-07-20

This update introduces filtering, categorization and improvements to caching performance for related methods

### Added
 - Added `CategorizableDatabaseInterface` and `SortableDatabaseInterface` for standardized filtering and sorting
 - Added enums `Categories` (`AttachmentCategory`, `AuditLogCategory`, `BlacklistCategory`, `EntityCategory`,
   `EvidenceCategory`, `OperatorCategory`, `ReportCategory`) and `OrderTypes` (`AttachmentOrderType`,
   `AuditLogOrderType`, `BlacklistOrderType`, `EntityOrderType`, `EvidenceOrderType`, `OperatorOrderType`,
   `ReportOrderType`) with their respective classes, these enums are used for sorting/filtering records from
   listing/search methods.
 - Added `OrderType` enum (ASC/DESC) and `RecordType` enum for search result typing
 - Added filtering parameters (`category`, `by`, `order`) to all listing methods: `ListAttachments`, `ListAuditLogs`,
   `ListBlacklist`, `ListEntities`, `ListEvidence`, `ListOperators`, `ListReports`, and their operator/entity scoped
   variants (`ListEntityAuditLogs`, `ListOperatorAuditLogs`, `ListEntityReports`, `ListOperatorReports`,
   `ListAssignedOperatorReports`)
 - Added sorting support to all manager classes (`buildReportSortClause`, `buildOperatorSortClause`,
   `buildAttachmentsSortClause`, `buildEvidenceSortClause`, `buildEntitySortClause`)
 - Updated `FederationClient` with filtering/sorting parameters for all listing methods
 - Added `SearchConfiguration` with per-resource-type enable/disable, public search, and max limit settings
 - Added global `/search` endpoint with `SearchManager`, `SearchResult` object, and per-resource search handlers
   (`SearchAttachments`, `SearchAuditLogs`, `SearchBlacklist`, `SearchEntities`, `SearchEvidence`, `SearchOperators`,
   `SearchReports`)
 - Added `ExtendBlacklist` method (`/blacklist/{uuid}/extend`) for extending blacklist lift timestamps
 - Added `UpdateOperatorName` method (`/operators/{uuid}/update-name`) for updating operator names
 - Added `TopThreats` method (`/entities/top-threats`) for displaying top-threat entities by reputation
 - Added `OPERATOR_NAME_CHANGED` and `BLACKLIST_EXTENDED` audit log types with `getCategory()` method
 - Added condition to prevent blacklisting an entity not related to an evidence record
 - Added metadata validation enforcement (empty key check in `Validate`)
 - Added try/catch to `generateString` for `RandomException` error handling
 - Added `AuditLogCategory::toCondition()` returning parameterized SQL conditions
 - Added tests for parameter filtering and security related tests
 - Added property to disable ncc's APCu usage for test units
 - Added `.dockerignore`
 - Added `tryFromCaseInsensitive()` to `OrderType`, `IncidentType`, `ClassificationFlag`, `RecordType`,
   `EntityRelationshipType`, all `Categories` enums, and all `OrderTypes` enums for case-insensitive parameter parsing
 - Added `RedisConnection` methods for search/listing result-set caching: `getSearchCacheKey()`, `cacheSearchResults()`,
   `getCachedSearchResults()`, `clearSearchCache()`, `getResultCacheTtl()`
 - Added result-set caching to all 7 listing methods (`getEntities`, `getEvidenceRecords`, `getEntries`,
   `getReports`, `getOperators`, `getAttachmentRecords`, audit `getEntries`), gated on `isPreCacheEnabled()`
 - Added result-set caching to all 7 search methods (`searchEntities`, `searchEvidence`, `searchBlacklist`,
   `searchReports`, `searchOperators`, `searchAttachments`, `searchAuditLogs`), gated on `isPreCacheEnabled()`
 - Added individual record pre-caching (`setRecords`) to all 7 search methods
 - Added cache invalidation (`clearSearchCache`) to every mutation method across all 7 managers so listing/search
   calls return fresh data after any create/update/delete/clean operation
 - Added `searchCacheTtl` configuration property (`redis.search_cache_ttl`, `FEDERATION_SEARCH_CACHE_TTL`, default 60s)

### Changed
  - Updated all manager classes with filtering/sorting parameters and `build*SortClause` methods
  - Updated `FederationClient` to include filtering parameters across all listing/search methods
  - Updated `DeleteBlacklist` method
  - Updated `CloseReport` to affect entity reputation based on `ClassificationFlag` (malicious: -1, normal: +1)
  - Updated `SubmitReport` to use UUID v7 and fixed report message parameter
  - Updated `ScanContent` to return standardized array via `toStandardArray()`
  - Updated `Utilities::isUuid` to accept both UUID v4 and v7 formats
  - Updated `Method` enum with new search/update/extend/top-threats routes and changed underscore paths to dashes
  - Updated `AuditLogType` with new cases and `getCategory()` mapping
  - Updated `UploadHandler`
  - Updated SQL schemas for UUID v7 support
  - Renamed `SecurityTestHelpers` trait to `TestHelpers`
  - Renamed `ClassificationTextGenerator` class to `TextGenerator`
  - Updated test bootstrap for helper file renames
  - Updated Dockerfile to be more efficient in the build process
  - Updated phpunit.xml to disable apcu caching for ncc during tests
  - Updated all listing method handlers to accept any capitalisation of `category`, `by`, and `order` parameters
  - Updated all manager classes to accept any capitalisation of the `by` sort parameter
  - Updated `SubmitReport`, `CloseReport`, `BlacklistEntity` to accept any capitalisation of `incident_type`,
    `classification_flag`, and `type` parameters
  - Updated `SetRelationship` to accept any capitalisation of `relationship_type`
  - Updated `Search` to accept any capitalisation of the `type` parameter
  - Updated `FederationClient::search()` to normalise type values to uppercase before sending
  - Restored `getValidTypeValues()` in `Search` (converted to dynamic from `RecordType::cases()`), used by `SpecificationGenerator` for OpenAPI schema generation
  - Added `by`, `order`, and `category` filtering/sorting parameters to all per-resource search handlers
    (`SearchAttachments`, `SearchAuditLogs`, `SearchBlacklist`, `SearchEntities`, `SearchEvidence`,
    `SearchOperators`, `SearchReports`) matching the listing handlers pattern
  - Updated all manager search methods (`searchAttachments`, `searchAuditLogs`, `searchBlacklist`,
    `searchEntities`, `searchEvidence`, `searchOperators`, `searchReports`) to accept optional
    `$category`, `$by`, and `$order` parameters with SQL sort clause and category filtering support
  - Updated `FederationClient` per-resource search methods (`searchAttachments`, `searchAuditLogs`,
    `searchBlacklist`, `searchEntities`, `searchEvidence`, `searchOperators`, `searchReports`) to accept
    optional `$category`, `$by`, and `$order` parameters with `applySortParams` normalisation
  - Added tests for search sorting and category filtering in `SearchTest`
  - Changed all 7 listing methods and 7 search methods to read/write result-set caches via `getCachedSearchResults()` /
    `cacheSearchResults()` when pre-caching is enabled
  - Fixed env var typo `FEspiciousDERATION_EVIDENCE_CACHE_TTL` → `FEDERATION_EVIDENCE_CACHE_TTL`

### Removed
  - Removed `isBlacklisted()`, added category/sort support to `getEntries()` to BlacklistManager
  - Removed unused `deleteEntity` variants from `EntitiesManager`



## [0.0.10] - 2026-07-16

Added `classificationFlag` property to `EvidenceRecord`


## [0.0.9] - 2026-07-16

This update introduces new methods, bug fixes and additional tests

### Added
 - Added a search functionality to the server, enabling the methods `/search`, `/entities/search`, `/audit/search`,
   `/evidence/search`, `/blacklist/search`, `/attachments/search`, `/operators/search` and  `/reports/search`
 - Added a check for operator name's uniqueness before creating an operator
 - Added method `/blacklist/{uuid}/extend` to allow extending blacklist record lift timestamps
 - Added new `ReportCategory` type and updated existing report methods to allow reports to be listed by an optional
   category using `OPENED`, `CLOSED`, `AUTOMATED`, `UNASSIGNED` and `ASSIGNED`
 - Added method `/blacklist/top-threats` to display an array of entities to be considered top threats based off their
   reputation score
 - Added method `/operator/update-name` to update the name of an existing operator

### Changed
 - Updated Dockerfile build order so that caching can improve the build speed
 - Changed all request paths from using an underscore `_` character to using a dash character instead `-`, fixed
   inconsistencies like `clearReputation` to become `clear-reputation` instead
 - Updated CloseReport so that upon closing a report, the entities reputation is affected depending on the classification flag.
 - Updated BlacklistClientTest to ensure that the timing is greater than or equal than just greater than
 - Updated ServerInformation to include information about reports

### Fixed
 - Updated BayesianClient
 - Ensured consistency across the implementation to use UUID v7

### Removed
 - Removed deprecated method from FederationClient `queryEntity`


## [0.0.8] - 2026-07-13

This update introduces specification interfaces, new API methods, BayesianServer integration, extensive test coverage,
CORS support, and numerous improvements across the codebase.

### Added
 - Added `system` operator for referencing the system as an in-operable operator
 - Added safeguards to prevent access as a system operator
 - Added support for content filtering & automated reports
 - Added `RequestSpecificationInterface`, `ObjectSpecificationInterface`, `StandardObjectInterface` and `ScannedContent` objects
 - Added ObjectSpecification methods to `AuditLog`, `BlacklistRecord`, `EntityRecord`, `ErrorResponse`, `EvidenceRecord`, `FileAttachmentRecord`, `OperatorRecord`, `ReportRecord`, `ReportSubmission`, `ServerInformation` and `UploadResult`
 - Added RequestSpecification methods to all API endpoint handlers and manager classes
 - Added `SpecificationGenerator` for OpenAPI specification generation
 - Added `UploadHandler`
 - Added `ScanningConfiguration`, `ScanningRules`, `SuggestedActionType`
 - Added `GenerateOperatorAccessToken` method
 - Added `ClearReputation` and `ClearRelationship` methods
 - Added `ListEntityReports` and `ListOperatorReports` methods
 - Added `SetRelationship` method
 - Added `UpdateTag` method
 - Added reports API methods: `SubmitReport`, `CloseReport`, `AssignOperator`, `AddEvidence`, `DeleteReport`, `GetReport`, `ListReports`
 - Added operator management API methods: `ManageOperatorPermissions`, `ManageManagementPermissions`, `ManageClientPermissions`
 - Added `GetSpecification` endpoint
 - Added `ListOperatorEvidence`, `ListOperatorBlacklist`, `ListOperatorAuditLogs`, `ListAssignedOperatorReports` methods
 - Added BayesianServer integration: `BayesianClient`, `BayesianAnalytics`, `BayesianClassification`, `BayesianLearn`, `BayesianServer`, `BayesianEventType`, `BayesianConfiguration`, `ClassificationFlag`
 - Added `ReportRecord` with reports SQL schema and `DatabaseTables` registration
 - Added CORS support with `allowed-origin` header parser
 - Added property validation for `$limit` and `$page` parameters
 - Added Attachment UUID validation
 - Added filename sanitization
 - Added Maintenance configuration values and database cleanup methods (`getOldRecords`, `cleanEntries`) to `ReportManager`, `FileAttachmentManager`, `EvidenceManager`, `EntitiesManager`, `BlacklistManager`, `AuditLogManager`
 - Added `ENTITY_UPDATED` audit log entry type
 - Added `LrProbability` property to `LabelClassification`
 - Added filtering for public entries
 - Added missing `expires` parameter
 - Added builtin operator check for `ManageManagementPermissions`
 - Added operator name restrictions
 - Added `const` type definitions
 - Added entity metadata validation
 - Added Metadata operations to `EntitiesManager`
 - Added `entity_relationship` record information
 - Added `update` column to evidence SQL schema
 - Added index for report column in `evidence.sql`
 - Added OpenJDK and BayesianServer to Docker environment
 - Added security test helpers trait with local PHP HTTP server for attachment-from-URL tests
 - Added sample classification data
 - Added improved cryptographic implementation for string generation
 - Added new test files: `ValidateTest`, `UtilitiesTest`, `ReportsClientTest`, `ObjectSchemaTest`, `MethodEnumTest`, `DataValidationTest`, `ContentScanTest`

### Changed
 - EntityRecord now contains metadata information and an update timestamp property
 - Updated the initialization workflow to autocorrect multiple system-defined operators
 - Updated RequestHandler to deny access as system
 - Implemented key-hashing for sensitive data, added methods for system operator
 - Renamed operator permissions to `client_permissions`, `management_permissions` and `operator_permissions` to avoid confusion
 - Refactored permission endpoints
 - Made `ENTITY_UPDATED` as public record by default
 - Renamed all references from "API Key" to "Access Token" for consistency
 - Renamed `BlacklistType` to `IncidentType`
 - Refactored `ScanContent` implementation and parameter signatures
 - Updated all manager classes with improved parameter validation and RequestSpecification integration
 - Updated `FederationServer` to include `BayesianClient` property
 - Updated SQL schemas: entities, evidence, reports, operators, audit_log
 - Updated `Configuration`, `ServerConfiguration`, `RedisConfiguration`, `BayesianConfiguration`
 - Updated `SuccessResponse` handler — flattened response structure
 - Updated CLI command handlers: `InitializeCommand`, `EditOperator`, `CreateOperator`
 - Updated `OperatorManager`, `EvidenceManager`, `EntitiesManager`, `ReportManager`
 - Updated `UploadAttachment`, `ListAttachments`, `GetAttachmentInfo`, `DownloadAttachment`, `DeleteAttachment` with RequestSpecification methods
 - Updated `ViewAuditEntry`, `ListAuditLogs` with RequestSpecification methods
 - Updated `AuditLogType` with new entry types (`OPERATOR_ACCESS_TOKEN_GENERATED`, `EVIDENCE_UPDATED`, `REPORT_CLOSED`, `REPORT_DELETED`)
 - Updated `ClassificationFlag` enum
 - Updated `Method` enum with new routes
 - Renamed `DEPENDENT` to `CHILD` relationship type
 - Renamed `canManageBlacklist` to `hasManagementPermissions`, `canManageOperators` to `hasOperatorPermissions`, `isClient` to `hasClientPermissions`, `setManageBlacklistPermission` to `setManagementPermissions`, `setClientPermission` to `setClientPermissions`
 - Changed "Refresh access token" to "Generate access token"
 - Updated default access token for system from `'0'` to `'none'`
 - Updated `SubmitEvidence` to allow entity resolution via UUID, hash or entity address
 - Updated `PushEntity` for host validation
 - Updated `DownloadAttachment` to return the file path instead of constructing temp paths
 - Refactored `NamedEntityType`
 - Updated `PhpUnit` helpers and bootstrap
 - Updated `Makefile` clean target
 - Updated main entrypoint
 - Updated `RedisConfiguration` and `BayesianConfiguration` default values
 - Updated existing test files: `AuditLogClientTest`, `BlacklistClientTest`, `ClientConfigurationTest`, `ClientTest`, `EntitiesClientTest`, `EntityQueryTest`, `EvidenceClientTest`, `FeaturesTest`, `OperatorsClientTest`, `ErrorHandlingAndEdgeCasesTest`, `PaginationTest`, `ServerInformationTest`
 - Moved sample data files to helper directory

### Removed
 - Removed old refresh methods
 - Removed unused files
 - Removed deprecated `use Exception` import and redundant catch blocks
 - Removed unnecessary comments throughout the codebase
 - Removed CSAM from `IncidentType`
 - Removed `testUploadFileWithExcessiveSize` and `testDownloadAttachmentInvalidPath` tests
 - Removed redundant generic catch blocks in `tearDown()` methods

### Fixed
 - Fixed order for database tables
 - Corrected response codes for various endpoints
 - Fixed table exists schema check
 - Disabled operations against builtin operators
 - Added missing sockets extension to CI workflow
 - Minor corrections and cleanup throughout



## [0.0.7] - 2026-06-03

This update introduces several improvements and bug fixes.

### Changed
 - Updated audit log messages to remove UUID references for operators and entities

### Fixed
 - Refactor timestamp parsing to handle numeric strings from Redis cache in multiple records



## [0.0.6] - 2026-06-02

This update introduces several improvements and bug fixes.

### Added
 - Add index for listing file attachments by creation date
 - Add configuration for maximum items in file attachments listing
 - Add pagination support for retrieving file attachment records
 - Add ListAttachments method for retrieving file attachments
 - Add listAttachments method for retrieving file attachments with pagination support
 - Add unit tests for listAttachments method including pagination and error handling



## [0.0.5] - 2026-06-02

This update introduces several improvements and bug fixes.

### Added
 - Add optional UUID fields for blacklist, evidence, and file attachments in AuditLog
 - Add optional UUID parameters for blacklist, evidence, and file attachments in createEntry method
 - Add optional UUID parameters for blacklist and evidence in BlacklistEntity
 - Add optional UUID parameters for evidence and attachment in AuditLog entry on deletion
 - Add blacklist UUID parameter to deletion process in DeleteBlacklist
 - Retrieve evidence details before checking existence in DeleteEvidence process

### Changed
 - Changed audit_log table structure by adding optional fields for blacklist, evidence, and file attachments; update
   timestamp index in audit_log.sql
 - Changed blacklist index to include UUID in blacklist.sql
 - Update evidence index to include UUID in evidence.sql
 - Update operators index to include UUID in operators.sql
 - Changed getTotalOperatorsCount method to use countRecords

### Fixed
 - Fixed deletion processes to ensure audit log entries are created before deleting records in DeleteAttachment,
   DeleteBlacklist, and DeleteEvidence

### Added
 - Add optional filename parameter to uploadFileAttachment and improve original name handling



## [0.0.4] - 2026-06-02

This update introduces several improvements and bug fixes.

### Added
 - Added a check & fix stage for the initialization command to ensure the root operator is configured correctly

### Changed
 - Prevent operations on reserved 'root' operator

### Removed
 - Removed deprecated use of finfo_close



## [0.0.3] - 2026-06-01

Removed all deprecated usage of curl_close


## [0.0.2] - 2026-06-01

This update introduces a bug fix

### Fixed
 - Validate URL scheme and host in constructor of FederationClient


## [0.0.1] - 2026-06-01

Initial release of FederationLib
