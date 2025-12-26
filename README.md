# File Labels for Nextcloud

User-specific labels for files with sensitive content hiding.

## Features

- **User-specific labels**: Each user manages their own labels independently
- **Key-value pairs**: Flexible metadata like `sensitive=true`, `project=work`
- **Hide previews**: Files labeled `sensitive=true` show a placeholder instead of preview
- **WebDAV integration**: Labels exposed via DAV property for client sync
- **OCS API**: Programmatic access for automation
- **Files app sidebar integration**: View and manage labels directly in the Files app UI

## Installation

### Development

```bash
# Install dependencies
npm install

# Build the frontend
npm run build

# Or watch for changes during development
npm run dev

# Start Nextcloud with the app mounted
podman-compose up -d

# Access at http://localhost:8080
# Login: admin / admin

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

# Set a label
curl -u admin:admin -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/sensitive" \
  -H "OCS-APIREQUEST: true" \
  -d "value=true"

# Delete a label
curl -u admin:admin -X DELETE "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42/sensitive" \
  -H "OCS-APIREQUEST: true"

# Bulk set labels
curl -u admin:admin -X PUT "http://localhost:8080/ocs/v2.php/apps/files_labels/api/v1/labels/42" \
  -H "OCS-APIREQUEST: true" \
  -H "Content-Type: application/json" \
  -d '{"labels": {"sensitive": "true", "project": "work"}}'
```

### WebDAV Property

Labels are exposed as `{http://nextcloud.org/ns}labels` property:

```xml
<nc:labels>
  {"sensitive": "true", "project": "work"}
</nc:labels>
```

## Label Key Format

- Must match pattern: `[a-z0-9_:.-]+`
- Maximum length: 64 characters
- Examples: `sensitive`, `project`, `app.myapp:status`

## Reserved Labels

| Key | Value | Effect |
|-----|-------|--------|
| `sensitive` | `true` | Hides file preview (shows placeholder) |

## License

AGPL-3.0-or-later
