# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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