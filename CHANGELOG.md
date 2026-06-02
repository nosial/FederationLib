# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.5] - Ongoing

This update introduces several improvements and bug fixes.

### Changed
 - Changed audit_log table structure by adding optional fields for blacklist, evidence, and file attachments; update
   timestamp index
 - Changed blacklist index to include UUID
 - Update entities index to include UUID for improved querying

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