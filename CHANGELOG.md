# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2025-01-XX

### Added
- Admin settings panel for configuring rate limits
- Configurable maximum labels per user (default: 1000)
- Configurable maximum labels per file (default: 100)
- WebDAV PROPPATCH support for setting labels
- Accessibility improvements (ARIA labels, keyboard navigation)
- Frontend unit tests with Jest
- End-to-end tests with Playwright
- Integration tests for API endpoints
- Bulk label operations API endpoint

### Changed
- Improved error handling with consistent 404 responses for permission errors
- Updated copyright year to 2025
- Enhanced sidebar tab UI with loading states

### Fixed
- WebDAV property caching for better performance
- Permission checks for shared files

## [0.1.0] - 2024-12-22

### Added
- Initial release
- User-specific file labels (key-value pairs)
- OCS REST API for label management
  - GET `/labels/{fileId}` - retrieve labels
  - POST `/labels/bulk` - bulk retrieve labels
  - PUT `/labels/{fileId}/{key}` - set label
  - PUT `/labels/{fileId}` - bulk set labels
  - DELETE `/labels/{fileId}/{key}` - delete label
- WebDAV property `{http://nextcloud.org/ns}labels`
- Files app sidebar tab for managing labels
- Event bus integration (`files_labels:label-changed`)
- Automatic cleanup on file/user deletion
- PHP Service layer for other apps to consume labels

[Unreleased]: https://github.com/jeffWelling/nextcloud-files-labels/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/jeffWelling/nextcloud-files-labels/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/jeffWelling/nextcloud-files-labels/releases/tag/v0.1.0
