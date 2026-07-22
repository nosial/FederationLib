# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


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