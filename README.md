# File Labels for Nextcloud

[![Tests](https://github.com/jeffWelling/nextcloud-files-labels/actions/workflows/test.yml/badge.svg)](https://github.com/jeffWelling/nextcloud-files-labels/actions/workflows/test.yml)
[![Lint](https://github.com/jeffWelling/nextcloud-files-labels/actions/workflows/lint.yml/badge.svg)](https://github.com/jeffWelling/nextcloud-files-labels/actions/workflows/lint.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-28%20%7C%2029%20%7C%2030%20%7C%2031-blue?logo=nextcloud)](https://nextcloud.com)

A primitive for attaching user-specific key-value labels to files.

## What This App Does

File Labels provides a **foundation for building other apps**. By itself, it simply allows users to attach arbitrary key-value metadata to files. The real power comes from other apps that consume these labels to provide functionality.

This app provides:
- A database schema for storing per-user, per-file labels
- An OCS REST API for reading and writing labels
- A WebDAV property for client sync integration
- A sidebar tab in the Files app for managing labels
- An admin settings page for configuring the maximum labels per user

## Apps Built on File Labels

### File Spoilers

The [File Spoilers](https://github.com/jeffWelling/nextcloud-files-spoilers) app uses labels to hide file previews.

## Features

- **User-specific labels**: Each user manages their own labels independently
- **Key-value pairs**: Flexible metadata like `category=work`, `status=draft`, `priority=high`
- **Bulk operations**: The API supports efficient batch retrieval (fetch labels for up to 1000 files in a single request) and batch updates (set multiple labels on a file atomically)
- **Files app integration**: Sidebar tab for viewing and editing labels
- **OCS API**: Programmatic access for automation and other apps
- **WebDAV property**: Labels exposed for desktop/mobile client sync
- **Admin controls**: Configure the maximum number of labels per user (default: 10,000)

## Installation

### Development

Nextcloud apps with custom UI components require a build step to bundle JavaScript and Vue components into files that browsers can execute. The source code in `src/` uses modern JavaScript features and Vue single-file components that need to be compiled.

```bash
# Install Node.js dependencies
npm install

# Build the frontend (compiles src/ to js/)
npm run build

# Start Nextcloud with the app mounted
podman-compose up -d

# Enable the app
podman exec -u www-data nextcloud-files-labels-nextcloud-1 php occ app:enable files_labels
```

### Production

1. Download from Nextcloud App Store
2. Or clone to `custom_apps/files_labels` and enable via admin UI

## API

### OCS REST API

All examples use HTTP Basic Authentication with format `username:password`.

```bash
# Get labels for a file
# Replace myuser:mypassword with your Nextcloud credentials
curl -u myuser:mypassword "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42" \
  -H "OCS-APIREQUEST: true"

# Get labels for multiple files (bulk)
# Returns labels for up to 1000 files in a single request
curl -u myuser:mypassword -X POST "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/bulk" \
  -H "OCS-APIREQUEST: true" \
  -H "Content-Type: application/json" \
  -d '{"fileIds": [42, 43, 44]}'

# Set a label
curl -u myuser:mypassword -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/category" \
  -H "OCS-APIREQUEST: true" \
  -d "value=work"

# Delete a label
curl -u myuser:mypassword -X DELETE "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/category" \
  -H "OCS-APIREQUEST: true"

# Bulk set labels on a file (set multiple labels atomically)
curl -u myuser:mypassword -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42" \
  -H "OCS-APIREQUEST: true" \
  -H "Content-Type: application/json" \
  -d '{"labels": {"category": "work", "priority": "high"}}'
```

### WebDAV Property

Labels are exposed as `{http://nextcloud.org/ns}labels` property:

```xml
<nc:labels>
  {"category": "work", "priority": "high"}
</nc:labels>
```

## Label Key/Value Format

**Keys:**
- Must match pattern: `[a-z0-9_.-]+` (lowercase letters, numbers, underscores, dots, hyphens)
- Maximum length: 255 characters

**Values:**
- Any UTF-8 string
- Maximum length: 255 characters

**Examples:**

| Key | Value | Use Case |
|-----|-------|----------|
| `category` | `personal` | Organize files by category |
| `category` | `business` | Organize files by category |
| `project` | `operation placate` | Associate files with a project |
| `sensitive` | `true` | Mark files as sensitive (used by File Spoilers) |
| `status` | `draft` | Track document workflow |
| `priority` | `high` | Prioritize files |

## Building Your Own App on File Labels

To consume labels from your PHP app:

```php
// Get the LabelsService via dependency injection
$labelsService = \OC::$server->get(\OCA\FilesLabels\Service\LabelsService::class);

// Get labels for a file (returns Label[] entities)
$labels = $labelsService->getLabelsForFile($fileId);

// Get labels for multiple files efficiently (returns array<int, Label[]>)
$labelsMap = $labelsService->getLabelsForFiles([$fileId1, $fileId2, $fileId3]);

// Check if a file has a specific label with a specific value
$hasLabel = $labelsService->hasLabel($fileId, 'category', 'work');

// Check if a file has a label key (any value)
$hasAnyCategory = $labelsService->hasLabel($fileId, 'category');

// Find all files with a specific label
$fileIds = $labelsService->findFilesByLabel('sensitive', 'true');

// Set a label on a file
$label = $labelsService->setLabel($fileId, 'category', 'work');

// Set multiple labels atomically
$labels = $labelsService->setLabels($fileId, [
    'category' => 'work',
    'priority' => 'high'
]);

// Delete a label
$deleted = $labelsService->deleteLabel($fileId, 'category');
```

Listen for label changes via the event bus (frontend JavaScript):

```javascript
import { subscribe } from '@nextcloud/event-bus'

subscribe('files_labels:label-changed', ({ fileId, labels }) => {
  // React to label changes
  // labels is an object: { key: value, ... }
})
```

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

See the [LICENSE](LICENSE) file for the full license text.

SPDX-License-Identifier: AGPL-3.0-or-later
