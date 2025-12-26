# File Labels for Nextcloud

A primitive for attaching user-specific key-value labels to files.

## What This App Does

File Labels provides a **foundation for building other apps**. By itself, it simply allows users to attach arbitrary key-value metadata to files. The real power comes from other apps that consume these labels to provide functionality.

This app provides:
- A database schema for storing per-user, per-file labels
- An OCS REST API for reading and writing labels
- A WebDAV property for client sync integration
- A sidebar tab in the Files app for managing labels

## Apps Built on File Labels

### File Spoilers

The [File Spoilers](https://github.com/your-repo/nextcloud-files-spoilers) app uses labels to hide file previews. Users configure trigger labels (e.g., `sensitive=true`), and any file with matching labels shows a placeholder instead of its preview. Click to reveal.

## Features

- **User-specific labels**: Each user manages their own labels independently
- **Key-value pairs**: Flexible metadata like `category=work`, `status=draft`, `priority=high`
- **Bulk operations**: Set or retrieve labels for multiple files efficiently
- **Files app integration**: Sidebar tab for viewing and editing labels
- **OCS API**: Programmatic access for automation and other apps
- **WebDAV property**: Labels exposed for desktop/mobile client sync

## Installation

### Development

```bash
# Install dependencies
npm install

# Build the frontend
npm run build

# Start Nextcloud with the app mounted
podman-compose up -d

# Enable the app
podman exec -u www-data nextcloud-files-labels-nextcloud-1 php occ app:enable files_labels
```

### Production

1. Download from Nextcloud App Store (once published)
2. Or clone to `custom_apps/files_labels` and enable via admin UI

## API

### OCS REST API

```bash
# Get labels for a file
curl -u admin:admin "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42" \
  -H "OCS-APIREQUEST: true"

# Get labels for multiple files (bulk)
curl -u admin:admin -X POST "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/bulk" \
  -H "OCS-APIREQUEST: true" \
  -H "Content-Type: application/json" \
  -d '{"fileIds": [42, 43, 44]}'

# Set a label
curl -u admin:admin -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/category" \
  -H "OCS-APIREQUEST: true" \
  -d "value=work"

# Delete a label
curl -u admin:admin -X DELETE "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/category" \
  -H "OCS-APIREQUEST: true"

# Bulk set labels on a file
curl -u admin:admin -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42" \
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

## Label Key Format

- Must match pattern: `[a-z0-9_:.-]+`
- Maximum length: 64 characters
- Examples: `category`, `project`, `app.myapp:status`

## Building Your Own App on File Labels

To consume labels from your app:

```php
// Get the LabelsService
$labelsService = \OC::$server->get(\OCA\FilesLabels\Service\LabelsService::class);

// Get labels for a file
$labels = $labelsService->getLabelsForFile($fileId);

// Get labels for multiple files (efficient bulk operation)
$labelsMap = $labelsService->getLabelsForFiles([$fileId1, $fileId2, $fileId3]);

// Check if a file has a specific label
$hasLabel = $labelsService->hasLabel($fileId, 'category', 'work');
```

Listen for label changes via the event bus (frontend):

```javascript
import { subscribe } from '@nextcloud/event-bus'

subscribe('files_labels:label-changed', ({ fileId, labels }) => {
  // React to label changes
})
```

## License

AGPL-3.0-or-later
