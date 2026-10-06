# FederationLib Plugins

A plugin is an [ncc](https://git.n64.cc/nosial/ncc) package that extends FederationLib without modifying it. It can add
API routes, hook into FederationLib's own routes, and react to or take part in what happens inside FederationLib:
content scans, entity queries, changes written to the database and audit log entries. Plugin code runs in the same
process as FederationLib, so it can use everything FederationLib's own code uses: the request, the authenticated
operator, the managers, the database, the cache and the configuration.

FederationLib only needs to know which plugins to load. Each plugin is responsible for its own configuration.

## Table of contents

<!-- TOC -->
* [FederationLib Plugins](#federationlib-plugins)
  * [Table of contents](#table-of-contents)
  * [What plugins can do](#what-plugins-can-do)
  * [Using plugins](#using-plugins)
    * [Installing and enabling](#installing-and-enabling)
    * [Docker](#docker)
    * [Validation](#validation)
  * [Developing plugins](#developing-plugins)
    * [How plugins work](#how-plugins-work)
    * [Creating a plugin](#creating-a-plugin)
    * [Configuration](#configuration)
    * [Request handlers](#request-handlers)
    * [Event handlers](#event-handlers)
      * [AUDIT_LOG](#audit_log)
      * [CONTENT_SCAN](#content_scan)
      * [QUERY_ENTITY](#query_entity)
      * [RECORD_CHANGE](#record_change)
    * [Accessing FederationLib](#accessing-federationlib)
      * [The request and the operator](#the-request-and-the-operator)
      * [Responses and errors](#responses-and-errors)
      * [Managers](#managers)
      * [Configuration, database and cache](#configuration-database-and-cache)
      * [Utilities](#utilities)
      * [Other FederationLib servers](#other-federationlib-servers)
    * [Example: a webhook notifier](#example-a-webhook-notifier)
    * [Guidelines](#guidelines)
    * [Testing a plugin](#testing-a-plugin)
  * [Testing the plugin system](#testing-the-plugin-system)
<!-- TOC -->

## What plugins can do

A plugin is a set of handlers, small classes that FederationLib calls at the right moment. There are two kinds.

**Request handlers** deal with HTTP requests. The simplest one adds a new route to the API, eg; `GET /my-plugin/stats`
returning statistics from your own tables. A request handler can also attach to an existing route, FederationLib's own
or another plugin's, to run before it (`PRE_REQUEST`, eg; to reject requests from unknown networks), instead of it
(`OVERRIDE`, to replace how it works) or after it (`POST_REQUEST`, eg; to collect metrics without making the client
wait).

**Event handlers** are called when something happens inside FederationLib. Two events let a plugin take part in a
request while it is being handled:

 - `CONTENT_SCAN` happens whenever content is scanned (`POST /scan`). The plugin sees the content and the entities found
   in it, and can classify the content, add its own scanning rules to the risk score, or reject the request before
   anything about it is recorded.
 - `QUERY_ENTITY` happens whenever an entity is queried (`GET /entities/{identifier}/query`). The plugin can add or
   remove related entities and blacklists, add metadata, change the suggested action, or reject the request.

The other two events let a plugin react to what already happened, without being able to affect it:

 - `RECORD_CHANGE` happens after a record is created, changed or deleted, eg; a report was created or an entity was
   blacklisted.
 - `AUDIT_LOG` happens after an audit log entry is created.

Plugins are not limited to what their handlers receive. They run in FederationLib's process and can use its managers,
database, cache and configuration, so a plugin can also act on its own: register and blacklist entities, submit
evidence, keep its own tables or talk to other FederationLib servers. This also means a plugin has the same access as
FederationLib itself, so only install plugins you trust.

When several plugins handle the same route or event, they take turns in the order they are configured.

Some example plugins and the building blocks they would use:

| Plugin                                                                  | Building blocks                                                                                                              |
|-------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------|
| Automatic moderation (eg; blacklisting entities with many reports)      | `RECORD_CHANGE` (`REPORT_CREATED`) with the managers, acting as the system operator                                          |
| Content classifiers (Bayesian, machine learning models, LLM moderation) | `CONTENT_SCAN` to classify content, `RECORD_CHANGE` (`EVIDENCE_CLASSIFIED`) to learn from the operators' classifications     |
| External reputation feeds (AbuseIPDB, Spamhaus, phishing lists)         | `CONTENT_SCAN` to add scanning rules to the risk score, `QUERY_ENTITY` to add the feed's blacklists or metadata to queries   |
| Illegal content filters (eg; CSAM hash matching)                        | `CONTENT_SCAN` to reject the request before anything about the content is recorded                                           |
| Entity enrichment (WHOIS, GeoIP, ASN)                                   | `QUERY_ENTITY` to add metadata to responses, or `RECORD_CHANGE` (`ENTITY_CREATED`) to store it with the entity               |
| Notifications (webhooks, Discord, Matrix, email)                        | `RECORD_CHANGE` (eg; `REPORT_CREATED`, `BLACKLIST_CREATED`) or `AUDIT_LOG`                                                   |
| Audit exports (SIEM, log archives)                                      | `AUDIT_LOG`                                                                                                                  |
| Federation between servers (mirroring blacklists, sharing reports)      | `RECORD_CHANGE` with `FederationClient` to push to other servers, a new route to receive from them                           |
| Access control (IP allowlists, rate limiting, extra authentication)     | A `PRE_REQUEST` handler on `/*` that rejects requests before FederationLib handles them                                      |
| Custom endpoints (statistics, dashboards, exports, admin tools)         | New routes that use the managers                                                                                             |
| Changing FederationLib's endpoints                                      | `OVERRIDE` to replace a route, `PRE_REQUEST` to validate its input, `POST_REQUEST` for work after the response (eg; metrics) |

BayesianPlugin, which provides FederationLib's content classification, is a complete production plugin.
[tests/TestPlugin](tests/TestPlugin) demonstrates every feature of the plugin system.

## Using plugins

### Installing and enabling

Install the plugin with ncc (from a remote source or a built package), then add its package name to FederationLib's
`plugins` configuration, or to the comma-separated `FEDERATION_PLUGINS` environment variable:

```shell
ncc install --package="nosial/plugin1@github"
```

```yaml
plugins:
    - net.nosial.plugin1
    - com.example.my_plugin
```

Plugins are executed in the order they are listed, which matters when several plugins handle the same route or event.
Refer to each plugin's documentation for its configuration.

### Docker

The docker image prepares plugins with the docker-only `REQUIRE_PLUGINS` variable: a comma-separated list of anything
`ncc install` accepts (remote packages or `.ncc` files), installed or updated before `federationlib init`. The container
does not start if a plugin fails to install. `FEDERATION_PLUGINS` then enables the installed plugins by package name.

```yaml
environment:
  - REQUIRE_PLUGINS=nosial/plugin1@github,/opt/plugins/com.example.my_plugin.ncc
  - FEDERATION_PLUGINS=net.nosial.plugin1,com.example.my_plugin
  - PLUGIN1_GREETING=Hello # Defined by the plugin's own configuration
```

The image comes with [BayesianPlugin](https://github.com/nosial/BayesianPlugin) (`nosial/BayesianPlugin@github`)
installed, the client for the bundled BayesianServer. It's always enabled as the first plugin: `net.nosial.bayesian_plugin`
is prepended to `FEDERATION_PLUGINS` (or becomes its only entry when the variable is not set), the example above
effectively loads `net.nosial.bayesian_plugin,net.nosial.plugin1,com.example.my_plugin`.

Plugins that need more than a package, such as an additional service or system dependency, can be prepared with the
docker-only `AUTOSTART` variable: the path to a shell script inside the container, executed on every container start
before the plugins are installed and before `federationlib init`. The script runs directly when it is executable (using
its shebang), otherwise with `bash`. The container does not start if the script is missing or exits with a non-zero
code. Since it runs on every start, the script should be safe to run more than once.

```yaml
environment:
  - AUTOSTART=/opt/autostart.sh
volumes:
  - ./autostart.sh:/opt/autostart.sh:ro
```

### Validation

`federationlib init` imports every enabled plugin and fails if any of the following is true, reporting every issue at
once:

 - The package is not installed, or was not built with a `federationlib` package option
 - A handler class does not exist or does not implement the required interface
 - A new route is already handled by FederationLib or another plugin, or two plugins override the same route

Handlers that can never be executed (eg; a `PRE_REQUEST` handler for a route nothing handles) are logged as warnings.

## Developing plugins

### How plugins work

A plugin is an ordinary ncc project. Its build carries a `federationlib` package option that lists its handlers, each
with the class that implements it:

 - **Request handlers** are matched against every request by method and path. Their class extends
   `PluginRequestHandler` and implements `handleRequest()`, exactly like FederationLib's own request handlers.
 - **Event handlers** are executed when an event is produced. Their class implements the event's interface, a single
   static method that receives an object describing the event.

FederationLib imports the enabled plugins the first time they are needed in a process: on a request, during
`federationlib init`, or when an event is produced by the CLI. FederationLib, ConfigLib, LogLib2 and FederationLib's
other dependencies are already loaded when a plugin's code runs. Handlers are static and run synchronously in that
process. They keep no state between requests, other than what they store themselves (eg; in Redis or the database).

### Creating a plugin

A plugin declares its handlers in the `federationlib` option of its `project.yml`. The plugin does not depend on
`net.nosial.federation`, because FederationLib is provided at runtime.

```yaml
source: src
default_build: release
assembly:
  name: MyPlugin
  package: com.example.my_plugin
  version: 1.0.0
build_configurations:
  -
    name: debug
    output: 'target/debug/${ASSEMBLY.PACKAGE}.ncc'
    type: ncc
    options:
      federationlib: &federationlib
        request_handlers:
          -
            path: /my-plugin/hello
            class: \MyPlugin\RequestHandlers\HelloHandler::class
            request_method: GET
        event_handlers:
          -
            event: AUDIT_LOG
            class: \MyPlugin\EventHandlers\AuditLogHandler::class
  -
    name: release
    output: 'target/release/${ASSEMBLY.PACKAGE}.ncc'
    type: ncc
    options:
      federationlib: *federationlib
```

Package options belong to a single build configuration, so every build configuration that may be installed needs the
`federationlib` option. The YAML anchor (`&federationlib`) and alias (`*federationlib`) avoid repeating it. Classes may
be written as `\MyPlugin\Foo`, `MyPlugin\Foo` or `\MyPlugin\Foo::class`.

Build and install the plugin with ncc, then enable it as described in [Installing and enabling](#installing-and-enabling):

```shell
ncc build --configuration release
ncc install --package="target/release/com.example.my_plugin.ncc" --yes --reinstall
```

### Configuration

A plugin that needs configuration uses its own ConfigLib instance, with its own defaults and environment variables.
Values set by environment variables are strings, so parse booleans accordingly (eg;
`filter_var($value, FILTER_VALIDATE_BOOLEAN)`).

```php
namespace MyPlugin;

class Configuration
{
    private static ?\ConfigLib\Configuration $configuration = null;

    public static function get(string $key, mixed $default=null): mixed
    {
        if(self::$configuration === null)
        {
            self::$configuration = new \ConfigLib\Configuration('my_plugin');
            self::$configuration->setDefault('greeting', 'Hello', 'MY_PLUGIN_GREETING');

            // Only save if the configuration file does not exist or we're in CLI mode
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }
        }

        return self::$configuration->get($key, $default);
    }
}
```

### Request handlers

| Property             | Required | Description                                                                                            |
|----------------------|----------|--------------------------------------------------------------------------------------------------------|
| `path`               | Yes      | The request path, see the path patterns below                                                          |
| `class`              | Yes      | The request handler class                                                                              |
| `request_method`     | Yes      | Comma-separated string or list of `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS` or `PUSH` |
| `execution_priority` | No       | How the handler is executed relative to the route's request handler, see the priorities below          |

| Path pattern        | Matches                                                                                          |
|---------------------|--------------------------------------------------------------------------------------------------|
| `/my-plugin/hello`  | Exactly `/my-plugin/hello`                                                                       |
| `/my-plugin/{uuid}` | A single path segment, available as `getPathParameter('uuid')`                                   |
| `/my-plugin/*`      | `/my-plugin` and every path below it, the `*` must be the last segment (`/*` matches every path) |

| Execution Priority | Description                                                                                                                                                   |
|--------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| *(none)*           | Provides a new route                                                                                                                                          |
| `PRE_REQUEST`      | Executed before the request handler, it may only do something beforehand or prevent the request handler from executing by responding or throwing an exception |
| `POST_REQUEST`     | Executed after the response was sent, any response it attempts is ignored and its errors are only logged                                                      |
| `OVERRIDE`         | Executed instead of the request handler                                                                                                                       |

For each request, every matching `PRE_REQUEST` handler is executed (in the order the plugins are configured) until one
responds. Then the request is handled by the `OVERRIDE` handler if there is one, otherwise by FederationLib's request
handler, otherwise by the plugin's new route. Finally every matching `POST_REQUEST` handler is executed. A request that
nothing handles is rejected without executing any of them.

The handler class extends `FederationLib\Classes\PluginRequestHandler`. It can use everything described in
[Accessing FederationLib](#accessing-federationlib), plus the following:

| Method                    | Returns                                                                      |
|---------------------------|------------------------------------------------------------------------------|
| `getPlugin()`             | The `Plugin` the handler belongs to (package name, version, handlers)        |
| `getPathParameter($name)` | The value of a `{name}` placeholder of the path, `null` if there is none     |
| `getPathParameters()`     | Every placeholder value, eg; `['uuid' => '...']`                             |
| `getExecutionPriority()`  | The `ExecutionPriority` the handler is executed with, `null` for a new route |

```php
namespace MyPlugin\RequestHandlers;

use FederationLib\Classes\PluginRequestHandler;
use FederationLib\FederationServer;

class HelloHandler extends PluginRequestHandler
{
    public static function handleRequest(): void
    {
        $operator = FederationServer::requireAuthenticatedOperator();
        self::successResponse([
            'greeting' => \MyPlugin\Configuration::get('greeting'),
            'operator' => $operator->getName(),
        ]);
    }
}
```

A `PRE_REQUEST` handler on `/*` sees every request, eg; to only allow known networks:

```php
class AllowlistHandler extends PluginRequestHandler
{
    public static function handleRequest(): void
    {
        if(!in_array($_SERVER['REMOTE_ADDR'] ?? '', \MyPlugin\Configuration::get('allowed_addresses', []), true))
        {
            // Responding (or throwing) prevents FederationLib from handling the request
            throw new \FederationLib\Exceptions\RequestException('Forbidden', \FederationLib\Enums\HttpResponseCode::FORBIDDEN);
        }
    }
}
```

### Event handlers

| Property | Required | Description                                                                                          |
|----------|----------|------------------------------------------------------------------------------------------------------|
| `event`  | Yes      | The event the handler is executed for                                                                |
| `class`  | Yes      | The event handler class, it implements the event's interface                                         |
| `filter` | No       | A list (or comma-separated string) of the values the handler is executed for, every value if omitted |

| Event           | Interface (`FederationLib\Interfaces\`) | Method                                     | Filter values                                       |
|-----------------|-----------------------------------------|--------------------------------------------|-----------------------------------------------------|
| `AUDIT_LOG`     | `AuditLogEventHandlerInterface`         | `handleAuditLog(AuditLog $auditLog)`       | Audit log types, eg; `OPERATOR_CREATED`             |
| `CONTENT_SCAN`  | `ContentScanEventHandlerInterface`      | `handleContentScan(ContentScan $scan)`     | Not supported                                       |
| `QUERY_ENTITY`  | `QueryEntityEventHandlerInterface`      | `handleQueryEntity(EntityQuery $query)`    | Not supported                                       |
| `RECORD_CHANGE` | `RecordChangeEventHandlerInterface`     | `handleRecordChange(RecordChange $change)` | [Change types](#record_change), eg; `REPORT_CLOSED` |

`AUDIT_LOG` and `RECORD_CHANGE` handlers only observe: any exception they throw is logged, and the operation that
produced the event is never affected. `CONTENT_SCAN` and `QUERY_ENTITY` handlers take part in a request and can change
its response or reject it. If one of them fails with any other exception, its changes are discarded and the request
continues. Event handlers are executed in the order the plugins are configured, and must never send a response
themselves.

#### AUDIT_LOG

Executed whenever an audit log entry is created. The handler receives the `AuditLog` entry, with:

 - `getUuid()`, `getType()` (an `AuditLogType`), `getMessage()` and `getTimestamp()`
 - The UUIDs of the related records: `getOperatorUuid()`, `getEntityUuid()`, `getBlacklistUuid()`, `getEvidenceUuid()`
   and `getFileAttachmentUuid()`

Entries created by an event handler are recorded but not dispatched again.

```php
class SiemExportHandler implements \FederationLib\Interfaces\AuditLogEventHandlerInterface
{
    public static function handleAuditLog(\FederationLib\Objects\AuditLog $auditLog): void
    {
        // eg; forward $auditLog->toArray() to a SIEM
    }
}
```

#### CONTENT_SCAN

Executed once per content scan request (`POST /scan`), after FederationLib resolved the entities and before anything
about the scan is recorded or returned. The `ContentScan` provides the request and FederationLib's findings:

| Method                        | Returns                                                                                      |
|-------------------------------|----------------------------------------------------------------------------------------------|
| `getEvidence()`               | The evidence items as `ContentInput` (text content, note, tag, confidentiality and metadata) |
| `getTextContents()`           | The text content of every evidence item that has any                                         |
| `getAuthorIdentifier()`       | The author as provided in the request, `null` if none was provided                           |
| `getAuthorEntity()`           | The resolved author (`ResolvedEntity`: the entity, its parent and their active blacklists)   |
| `getResolvedEntities()`       | Every entity found in the content (`ResolvedEntity[]`)                                       |
| `getAuthenticatedOperator()`  | The operator that made the request, `null` when anonymous                                    |
| `getTopK()`, `getThreshold()` | The `top_k` and `threshold` parameters of the request, intended for classifiers              |
| `getAddedClassifications()`   | The classifications added by the handlers executed before                                    |
| `getScanResults()`            | The scanning rules added by the handlers executed before                                     |

The handler may optionally:

 - **Classify the content** with `addClassification(ContentClassification $classification, ?int $evidenceIndex)`. The
   response's `classification` is the worst flag of all the classifications with their average confidence, and the
   matching `CLASSIFICATION_*` scanning rule is weighted by the confidence. The evidence index (of `getEvidence()`)
   describes the evidence of automatically generated reports.
 - **Add scanning rules** with `addScanResult('MY_PLUGIN_RULE', $points)`. Rules are included in `scan_results` and
   the risk score like FederationLib's own: positive points lower the risk, negative points raise it and prevent the
   scan from improving the author's reputation. Points added to the same rule accumulate. Names are uppercase
   (`[A-Z][A-Z0-9_]*`) and can not be one of FederationLib's own rules.
 - **Reject the request** with `reject('Reason', $code)` (any 4xx, `403` by default) or by throwing a
   `RequestException`. The client receives an error response, the remaining handlers are not executed and nothing
   about the scan is recorded.

A plugin that must never let content through on failure (eg; illegal content detection) should catch its own errors
and reject the request.

```php
use FederationLib\Enums\HttpResponseCode;
use FederationLib\Interfaces\ContentScanEventHandlerInterface;
use FederationLib\Objects\Plugin\ContentScan;

// Influences the risk score with an external reputation service
class AbuseIpdbHandler implements ContentScanEventHandlerInterface
{
    public static function handleContentScan(ContentScan $contentScan): void
    {
        foreach($contentScan->getResolvedEntities() as $resolvedEntity)
        {
            if(self::isReported($resolvedEntity->getEntity()->getHost()))
            {
                $contentScan->addScanResult('ABUSEIPDB_REPORTED', -15.0);
            }
        }
    }
}

// Rejects content the server does not want to handle
class CsamHandler implements ContentScanEventHandlerInterface
{
    public static function handleContentScan(ContentScan $contentScan): void
    {
        if(self::detect($contentScan->getEvidence()))
        {
            $contentScan->reject('This server does not accept this content', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
        }
    }
}
```

#### QUERY_ENTITY

Executed once per query entity request (`GET /entities/{identifier}/query`), after FederationLib resolved the entity,
its relationship group and the active blacklists, and before the response is sent. The `EntityQuery` provides
`getIdentifier()` (as requested), `getEntityRecord()`, `getRelatedEntities()`, `getActiveBlacklists()` and
`getAuthenticatedOperator()` (`null` when anonymous). The handler may optionally change the response:

 - **Related entities** with `addRelatedEntity(EntityRecord $entity)` and `removeRelatedEntity($uuid)`. Removing an
   entity also removes its active blacklists.
 - **Active blacklists** with `addBlacklist(BlacklistRecord $blacklist)` (eg; from an external blocklist, it must
   belong to the queried entity or a related entity) and `removeBlacklist($uuid)`. The suggested action is derived from
   them like FederationLib's own.
 - **Entity metadata** with `addEntityMetadata(array $metadata)`, merged over the stored metadata. It is only included
   where entity metadata is visible to the client.
 - **The suggested action** with `setSuggestedAction(?SuggestedActionType $action, ?int $liftTimestamp)`, which
   replaces the derived one (`null` suggests no action), and `resetSuggestedAction()`.
 - **Reject the request** with `reject('Reason', $code)` (any 4xx, `403` by default) or by throwing a
   `RequestException`. The remaining handlers are not executed.

Changes only affect the response. Nothing is written to the database.

```php
use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
use FederationLib\Objects\Plugin\EntityQuery;

// Enriches the response with an external reputation service
class AbuseIpdbQueryHandler implements QueryEntityEventHandlerInterface
{
    public static function handleQueryEntity(EntityQuery $entityQuery): void
    {
        $entityQuery->addEntityMetadata(['abuseipdb_score' => self::getScore($entityQuery->getEntityRecord()->getHost())]);
    }
}
```

#### RECORD_CHANGE

Executed whenever a change is written to the database, after the change is written. The `RecordChange` provides
`getType()` (a `RecordChangeType`), `getRecordType()` (a `RecordType`) and `getUuid()`. `getRecord()` returns the
current state of the record: an `OperatorRecord`, `EntityRecord`, `EvidenceRecord`, `FileAttachmentRecord`,
`ReportRecord` or `BlacklistRecord`. The record is only retrieved when a handler asks for it, and is `null` for
deletions.

| Record     | Change types                                                                                        |
|------------|-----------------------------------------------------------------------------------------------------|
| Operator   | `OPERATOR_CREATED`, `OPERATOR_UPDATED`, `OPERATOR_DISABLED`, `OPERATOR_ENABLED`, `OPERATOR_DELETED` |
| Entity     | `ENTITY_CREATED`, `ENTITY_UPDATED`, `ENTITY_REPUTATION_UPDATED`, `ENTITY_DELETED`                   |
| Evidence   | `EVIDENCE_CREATED`, `EVIDENCE_UPDATED`, `EVIDENCE_CLASSIFIED`, `EVIDENCE_DELETED`                   |
| Attachment | `ATTACHMENT_CREATED`, `ATTACHMENT_DELETED`                                                          |
| Report     | `REPORT_CREATED`, `REPORT_OPERATOR_ASSIGNED`, `REPORT_CLOSED`, `REPORT_DELETED`                     |
| Blacklist  | `BLACKLIST_CREATED`, `BLACKLIST_EXTENDED`, `BLACKLIST_LIFTED`, `BLACKLIST_DELETED`                  |

 - A change is only produced when a record actually changed, eg; closing a report that does not exist produces nothing.
 - `EVIDENCE_CLASSIFIED` is produced when evidence is submitted with a classification (after `EVIDENCE_CREATED`),
   classified directly, or classified by closing a report. Classifications are immutable, so it is produced at most
   once per evidence record.
 - The following produce no changes: records removed by another deletion (eg; the evidence of a deleted entity),
   records removed by the retention cleanup or changed by the host migration, and the root and system operators
   created during initialization.
 - Changes made by an event handler are written but not dispatched again.

```php
use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
use FederationLib\Objects\Plugin\RecordChange;

// Configured with `filter: EVIDENCE_CLASSIFIED`, trains a classifier with the operators' decisions
class TrainingHandler implements RecordChangeEventHandlerInterface
{
    public static function handleRecordChange(RecordChange $change): void
    {
        $evidence = $change->getRecord();
        // eg; submit $evidence->getTextContent() labelled $evidence->getClassificationFlag()->value to a classifier
    }
}
```

### Accessing FederationLib

Plugins use FederationLib's own classes. Request handlers can use everything below. Event handlers can use everything
except the request and response helpers. Class names are relative to the `FederationLib` namespace.

#### The request and the operator

`FederationServer` provides the current request:

| Method                                        | Returns                                                                                    |
|-----------------------------------------------|--------------------------------------------------------------------------------------------|
| `getRequestMethod()`, `getPath()`, `getUri()` | The request method, path and URI                                                           |
| `getParameter($name)`                         | A parameter from the form data, the query string or the JSON body, `null` if it is missing |
| `getDecodedContent()`                         | The decoded JSON body, `null` if the body is not JSON                                      |
| `getInputContent()`                           | The raw request body                                                                       |
| `getAuthenticatedOperator()`                  | The `OperatorRecord` of the request's access token, `null` when anonymous                  |
| `requireAuthenticatedOperator()`              | The `OperatorRecord`, or a `401` error response when anonymous                             |
| `getServerInformation()`                      | The `ServerInformation` returned by `GET /`                                                |

An `OperatorRecord` provides `getUuid()` and `getName()`, and describes the operator's permissions with
`hasClientPermissions()`, `hasManagementPermissions()`, `hasOperatorPermissions()` and `isDisabled()`. Check them before
doing anything an operator could not do through FederationLib's own routes.

#### Responses and errors

A request handler responds with the helpers of `Classes\RequestHandler`:

| Method                                                 | Description                                                                                                                                  |
|--------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `self::successResponse($data, $code=200)`              | Sends `$data` as JSON. Objects are serialized through `toStandardArray()`/`toArray()`, so records never expose secrets such as access tokens |
| `self::errorResponse($message, $code=500)`             | Sends an error response                                                                                                                      |
| `throw new RequestException($message, $code)`          | Sends an error response, the most convenient way to fail from anywhere in the handler                                                        |
| `self::resolveEntityIdentifier($identifier, $message)` | Resolves a UUID, SHA-256 hash or entity address to an `EntityRecord`, `null` if it does not exist                                            |
| `self::omitEntityMetadata()`                           | `true` if entity metadata must be hidden from the client (an anonymous request on a server with private metadata)                            |
| `RequestHandler::isResponseSent()`                     | `true` once a response was sent                                                                                                              |

The status codes are in the `Enums\HttpResponseCode` enum.

#### Managers

The managers in `Classes\Managers` read and write FederationLib's records. They handle caching, and their writes
produce `RECORD_CHANGE` events like FederationLib's own writes do. The most useful methods are:

| Manager                 | Methods                                                                                                                                                                                                                                                                    |
|-------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `EntitiesManager`       | `getEntityByUuid()`, `getEntity($host, $id)`, `getEntityByIdentifier()`, `registerEntity()`, `updateEntityMetadata()`, `replaceEntityMetadata()`, `updateEntityReputation()`, `setEntityWhitelist()`, `resolveNamedEntities($text)`, `getTopThreats()`, `searchEntities()` |
| `EvidenceManager`       | `getEvidence()`, `getEvidenceByEntity()`, `getEvidenceByReport()`, `addEvidence()`, `updateClassificationFlag()`, `updateTag()`, `deleteEvidence()`                                                                                                                        |
| `ReportManager`         | `getReport()`, `getReportsByReportingEntity()`, `hasOpenReports()`, `createReport()`, `assignOperator()`, `closeReport()`                                                                                                                                                  |
| `BlacklistManager`      | `getBlacklistEntry()`, `getEntriesByEntity()`, `getActiveEntriesByEntities()`, `blacklistEntity()`, `extendBlacklistRecord()`, `liftBlacklistRecord()`                                                                                                                     |
| `OperatorManager`       | `getOperator()`, `getSystemOperator()`, `getOperators()`, `createOperator()`, `disableOperator()`, `enableOperator()`                                                                                                                                                      |
| `FileAttachmentManager` | `getRecord()`, `getRecordsByEvidence()`                                                                                                                                                                                                                                    |
| `AuditLogManager`       | `createEntry(AuditLogType $type, $message, $operatorUuid, $entityUuid, ...)`, `getEntries()`, `getEntriesByEntity()`                                                                                                                                                       |
| `SearchManager`         | `search($query, $limit, $page, $operator, $types)`                                                                                                                                                                                                                         |

A plugin that writes records on its own behalf should attribute them to the system operator
(`OperatorManager::getSystemOperator()`) and record them with `AuditLogManager::createEntry()`, like FederationLib does
for its automatic actions. Managers throw a `DatabaseOperationException` when the database fails, and an
`InvalidArgumentException` when their input is invalid.

#### Configuration, database and cache

| Class                                         | Use                                                                                                                                                                                                          |
|-----------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Classes\Configuration`                       | FederationLib's configuration: `getServerConfiguration()`, `getScanningConfiguration()`, `getRedisConfiguration()`, `getSearchConfiguration()`, `getMaintenanceConfiguration()`, `getPluginsConfiguration()` |
| `Classes\DatabaseConnection::getConnection()` | FederationLib's `PDO` connection, for plugins that keep their own tables (they create them themselves). Use the managers for FederationLib's own tables                                                      |
| `Classes\RedisConnection::getConnection()`    | FederationLib's `Redis` connection (`null` when Redis is disabled), eg; for rate limits or caching external lookups                                                                                          |
| `Classes\PluginManager::getPlugin($package)`  | Another enabled plugin, `null` if it is not enabled                                                                                                                                                          |

#### Utilities

| Class                                   | Methods                                                                                                                                                                                |
|-----------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Classes\Validate`                      | `uuid()`, `host()`, `domain()`, `url()`, `email()`, `ipv4()`, `ipv6()`, `metadata()` (whether an array is valid entity or evidence metadata)                                           |
| `Classes\Utilities`                     | `canonicalizeHost()`, `getRegistrableDomain()` (eg; `example.com` for `a.example.com`), `hashEntity($host, $id)`, `parseEntityAddress()`, `isUuid()`, `isSha256()`, `generateString()` |
| `Enums\NamedEntityType::extract($text)` | Every entity identifier (URL, email, domain, IP address, ...) found in a text, with its position                                                                                       |

#### Other FederationLib servers

`FederationClient` is the client for FederationLib's API, eg; to share data with another server:

```php
$client = new \FederationLib\FederationClient('https://federation.example.com', $accessToken);
$result = $client->queryEntity('example.com');
$client->pushEntity('spam.example.com');
```

### Example: a webhook notifier

This plugin posts a message to a webhook (eg; a Discord channel) whenever a report is created or an entity is
blacklisted. It also adds a route that operators with management permissions can use to send a test message. It
combines its own configuration, a filtered `RECORD_CHANGE` handler, a new route, `getRecord()` and the managers.

`project.yml`:

```yaml
source: src
default_build: release
assembly:
  name: WebhookNotifier
  package: com.example.webhook_notifier
  version: 1.0.0
build_configurations:
  -
    name: release
    output: 'target/release/${ASSEMBLY.PACKAGE}.ncc'
    type: ncc
    options:
      federationlib:
        request_handlers:
          -
            path: /webhook-notifier/test
            class: \WebhookNotifier\RequestHandlers\TestHandler::class
            request_method: POST
        event_handlers:
          -
            event: RECORD_CHANGE
            class: \WebhookNotifier\EventHandlers\NotifyHandler::class
            filter: REPORT_CREATED, BLACKLIST_CREATED
```

`src/WebhookNotifier/Webhook.php` reads the plugin's configuration (`WEBHOOK_NOTIFIER_URL`) and sends the messages:

```php
namespace WebhookNotifier;

class Webhook
{
    private static ?\ConfigLib\Configuration $configuration = null;

    public static function send(string $message): void
    {
        if(self::$configuration === null)
        {
            self::$configuration = new \ConfigLib\Configuration('webhook_notifier');
            self::$configuration->setDefault('url', null, 'WEBHOOK_NOTIFIER_URL');
        }

        $url = self::$configuration->get('url');
        if(empty($url))
        {
            return;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['content' => $message]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5, // Keep it short, the handler runs while a request is being handled
        ]);
        curl_exec($curl);
        curl_close($curl);
    }
}
```

`src/WebhookNotifier/EventHandlers/NotifyHandler.php` describes the change using its record:

```php
namespace WebhookNotifier\EventHandlers;

use FederationLib\Classes\Managers\EntitiesManager;
use FederationLib\Enums\RecordChangeType;
use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
use FederationLib\Objects\Plugin\RecordChange;
use WebhookNotifier\Webhook;

class NotifyHandler implements RecordChangeEventHandlerInterface
{
    public static function handleRecordChange(RecordChange $change): void
    {
        $record = $change->getRecord();
        if($record === null)
        {
            return;
        }

        match($change->getType())
        {
            // $record is a ReportRecord
            RecordChangeType::REPORT_CREATED => Webhook::send(sprintf('New %s report %s',
                $record->getIncidentType()->value, $record->getUuid())),
            // $record is a BlacklistRecord
            RecordChangeType::BLACKLIST_CREATED => Webhook::send(sprintf('%s was blacklisted (%s)',
                EntitiesManager::getEntityByUuid($record->getEntityUuid())?->getAddress() ?? $record->getEntityUuid(),
                $record->getType()->value)),
            default => null,
        };
    }
}
```

`src/WebhookNotifier/RequestHandlers/TestHandler.php` implements the `POST /webhook-notifier/test` route:

```php
namespace WebhookNotifier\RequestHandlers;

use FederationLib\Classes\PluginRequestHandler;
use FederationLib\Enums\HttpResponseCode;
use FederationLib\Exceptions\RequestException;
use FederationLib\FederationServer;
use WebhookNotifier\Webhook;

class TestHandler extends PluginRequestHandler
{
    public static function handleRequest(): void
    {
        $operator = FederationServer::requireAuthenticatedOperator();
        if(!$operator->hasManagementPermissions())
        {
            throw new RequestException('Management permissions are required', HttpResponseCode::FORBIDDEN);
        }

        Webhook::send(sprintf('Test message sent by %s', $operator->getName()));
        self::successResponse();
    }
}
```

Enable the plugin with `FEDERATION_PLUGINS=com.example.webhook_notifier` and configure it with `WEBHOOK_NOTIFIER_URL`.

### Guidelines

 - Keep handlers fast, they are executed while a request is being handled. Use short timeouts for external services.
 - Prefix routes (`/my-plugin/...`), scanning rules (`MY_PLUGIN_...`), metadata keys and Redis keys with your plugin's
   name to avoid conflicts with other plugins.
 - In `CONTENT_SCAN` and `QUERY_ENTITY` handlers, throw your own exceptions rather than `RequestException`, unless you
   mean to reject the request.
 - Check the operator's permissions in your routes, FederationLib does not check them for plugin routes.
 - Log with your own logger (eg; `new \LogLib2\Logger('com.example.my_plugin')`).
 - Declare every dependency other than FederationLib and its own dependencies in your ncc project.

### Testing a plugin

BayesianPlugin shows how to set up a plugin's tests: its PHPUnit bootstrap imports the plugin's build and FederationLib.
A plugin can be tested at three levels:

 - **Call a handler directly** with an event object you construct, eg; `new ContentScan([new ContentInput('text')],
   null, null, [])` or `new RecordChange(RecordChangeType::EVIDENCE_CLASSIFIED, $uuid, $evidenceRecord)`. Passing the
   record avoids the database.
 - **Dispatch events through FederationLib** with `PluginManager::setPlugins([Plugin::load('com.example.my_plugin')])`
   followed by `PluginManager::dispatchContentScan($contentScan)` (or `dispatchQueryEntity()`,
   `dispatchRecordChange()`, `dispatchAuditLog()`). This also verifies the plugin's `federationlib` option.
 - **Test routes and database changes** against a running FederationLib server with the plugin installed, using
   `FederationClient`.

## Testing the plugin system

[tests/TestPlugin](tests/TestPlugin) is used exclusively by FederationLib's test units and is never part of its build
or production image. The test environment (`docker-compose.test.yml`, also used by the CI) builds
[Dockerfile.test](Dockerfile.test), a copy of the production Dockerfile that also installs and enables the TestPlugin.
Keep both Dockerfiles in sync.

```shell
make test-env       # Starts the docker environment with the TestPlugin installed and enabled
make test           # Builds the TestPlugin (for the in-process tests) and runs all the test units
make test-env-down  # Stops the test environment and removes its volumes
```
