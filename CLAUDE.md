# File Labels for Nextcloud - Claude Code Documentation

## What This App Does

File Labels is a **primitive/foundation app** for Nextcloud that provides user-specific key-value labels on files. It's designed as a building block for other apps to consume, not as a standalone feature for end users.

Think of it as a generic metadata layer - by itself it just stores labels, but other apps can build sophisticated features on top of it. For example, the File Spoilers app uses labels to hide previews for files marked as sensitive.

## Design Philosophy

### It's a Primitive, Not a Feature

This app intentionally does ONE thing well: store and retrieve user-specific file labels. It provides:

- A database schema for labels
- An OCS REST API for programmatic access
- A WebDAV property for client sync
- A sidebar tab for manual label management
- An event bus for inter-app communication

### Building Blocks for Other Apps

Other apps should consume this app's data via:

1. **PHP Service Layer**: `LabelsService` provides all the methods you need
2. **Event Bus** (frontend): `files_labels:label-changed` event for reactive updates
3. **WebDAV Property**: Labels exposed as `{http://nextcloud.org/ns}labels` for client sync

### User-Specific Labels

Labels are **per-user**, not shared. Each user maintains their own independent set of labels for each file. This is intentional - it allows personal organization, privacy, and workflows without affecting other users.

## Project Structure

```
nextcloud-files-labels/
├── appinfo/
│   ├── info.xml                           # App metadata, dependencies, scripts
│   └── routes.php                         # OCS API route definitions
├── lib/
│   ├── AppInfo/
│   │   └── Application.php                # App bootstrap, event listeners
│   ├── Controller/
│   │   └── LabelsController.php           # OCS API endpoints
│   ├── DAV/
│   │   └── LabelsPlugin.php               # WebDAV property plugin (with caching)
│   ├── Db/
│   │   ├── Label.php                      # Entity class
│   │   └── LabelMapper.php                # Database operations
│   ├── Listener/
│   │   ├── FileDeletedListener.php        # Clean up labels on file deletion
│   │   ├── UserDeletedListener.php        # Clean up labels on user deletion
│   │   └── SabrePluginAddListener.php     # Register DAV plugin
│   ├── Migration/
│   │   └── Version000100Date20241222000000.php  # Database schema
│   └── Service/
│       ├── AccessChecker.php              # Permission checking (read/write)
│       └── LabelsService.php              # Business logic layer
├── src/
│   ├── main.js                            # Frontend entry point (sidebar registration)
│   └── views/
│       └── LabelsSidebarTab.vue           # Labels management UI component
├── tests/
│   ├── Unit/                              # Unit tests (7 test classes, ~2100 LOC)
│   ├── Integration/                       # Integration tests
│   └── E2E/                               # End-to-end tests
├── package.json                           # npm dependencies
├── webpack.config.js                      # Build configuration
├── composer.json                          # PHP dependencies
└── js/                                    # Build output (gitignored)
    └── files_labels-main.js               # Bundled frontend
```

## Build Commands

### Frontend Build

```bash
# Install dependencies
npm install

# Production build (minified)
npm run build

# Development build with watch mode
npm run dev
npm run watch  # alias for dev
```

Output: `js/files_labels-main.js` (automatically loaded by Nextcloud)

### PHP Dependencies

```bash
composer install  # For development dependencies only
```

## Testing Commands

### Backend (PHPUnit)

```bash
# From Nextcloud root directory
cd /path/to/nextcloud

# Run all tests
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml

# Run unit tests only
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml --testsuite unit

# Run specific test class
php -f tests/lib/TestRunner.php apps/files_labels/tests/Unit/Service/LabelsServiceTest.php

# With coverage
php -f tests/lib/TestRunner.php --coverage-html coverage apps/files_labels/tests/phpunit.xml
```

Test coverage:
- 7 unit test classes
- ~2100 lines of test code
- 100+ individual test methods
- See TESTING.md for detailed checklist

### Frontend (Manual)

```bash
# Build and test in development environment
npm run build
podman-compose up -d
podman exec -u www-data nextcloud-files-labels-nextcloud-1 php occ app:enable files_labels

# Access: http://localhost:8080
# Login: admin / admin
# Go to Files > select file > Labels tab in sidebar
```

See TESTING.md for comprehensive UI testing checklist.

## Common Development Tasks

### Adding a New API Endpoint

1. Add route in `appinfo/routes.php`
2. Add method in `lib/Controller/LabelsController.php`
3. Add business logic in `lib/Service/LabelsService.php` if needed
4. Write tests in `tests/Unit/Controller/LabelsControllerTest.php`

### Adding a New Database Field

1. Create new migration in `lib/Migration/`
2. Update `lib/Db/Label.php` entity (add property, getter, setter)
3. Update `lib/Db/LabelMapper.php` if query changes needed
4. Update tests

### Modifying the UI

1. Edit `src/views/LabelsSidebarTab.vue`
2. Run `npm run dev` (watch mode)
3. Hard refresh browser (Cmd+Shift+R)
4. Test thoroughly

### Debugging

**Backend:**
```bash
# Nextcloud logs
tail -f /var/www/html/data/nextcloud.log

# Or in Docker
podman logs -f nextcloud-files-labels-nextcloud-1
```

**Frontend:**
- Browser console (F12 > Console)
- Network tab for API calls
- Vue DevTools extension

## Architecture Notes

### Database Schema

Table: `file_labels`

```sql
CREATE TABLE file_labels (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  file_id BIGINT NOT NULL,              -- Nextcloud file ID
  user_id VARCHAR(64) NOT NULL,         -- Nextcloud user ID
  label_key VARCHAR(64) NOT NULL,       -- Label key (lowercase alphanumeric, .-_:)
  label_value TEXT NOT NULL,            -- Label value (up to 4096 chars)
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,

  UNIQUE INDEX (file_id, user_id, label_key),  -- One key per file per user
  INDEX (user_id, label_key),                  -- Query all files with label
  INDEX (file_id, user_id)                     -- Bulk fetch for directory
);
```

**Key Constraints:**
- Unique: One label key per file per user
- Indexes optimized for bulk operations (directory listings)

### Label Key Validation

Pattern: `[a-z0-9_:.-]+` (lowercase alphanumeric, dots, dashes, underscores, colons)
Max length: 64 characters

Valid examples:
- `category`
- `project`
- `app.myapp:status`
- `sensitive`
- `rating-stars`

Invalid:
- Uppercase letters
- Spaces
- Special characters (!@#$%^&*)

### Label Value Validation

- Max length: 4,096 characters
- Can be empty string
- Supports Unicode and all characters

### OCS API Endpoints

Base URL: `/ocs/v2.php/apps/files_labels/api/v1`

**GET** `/labels/{fileId}`
- Get all labels for a file
- Returns: `{ "key": "value", ... }`

**POST** `/labels/bulk`
- Get labels for multiple files
- Body: `{ "fileIds": [42, 43, 44] }`
- Returns: `{ "42": {"key": "value"}, "43": {...}, ... }`
- Max 1000 file IDs per request

**PUT** `/labels/{fileId}/{key}`
- Set or update a label
- Body: `{ "value": "..." }`
- Returns: Label entity

**PUT** `/labels/{fileId}`
- Bulk set multiple labels
- Body: `{ "labels": {"key1": "value1", "key2": "value2"} }`
- Returns: `{ "key1": "value1", ... }`

**DELETE** `/labels/{fileId}/{key}`
- Delete a label
- Returns: `{ "success": true }`

All endpoints return 404 for permission errors (to avoid leaking file existence).

### WebDAV Property

Labels exposed as: `{http://nextcloud.org/ns}labels`

Format: JSON object `{"key": "value", ...}`

**Performance optimization:**
- `LabelsPlugin` preloads labels for entire directories (avoids N+1 queries)
- Uses `CappedMemoryCache` for request-level caching
- Only preloads when property is requested

### Event Bus

**Frontend Event:** `files_labels:label-changed`

Payload:
```javascript
{
  fileId: 123,
  labels: { "key": "value", ... }
}
```

Emitted when labels are added/deleted via the UI. Other apps can listen for this to update their state.

Example:
```javascript
import { subscribe } from '@nextcloud/event-bus'

subscribe('files_labels:label-changed', ({ fileId, labels }) => {
  // React to label changes
  console.log(`Labels changed for file ${fileId}:`, labels)
})
```

### Permission Checking

`AccessChecker` service handles all permission checks:

- **canRead(fileId)**: User has read access to file
- **canWrite(fileId)**: User has UPDATE permission on file
- **filterAccessible(fileIds)**: Filter array to only accessible files

All API methods check permissions before operations. Returns 404 for permission errors to avoid leaking file existence.

## How Other Apps Can Build on This

### PHP Service Layer

```php
use OCA\FilesLabels\Service\LabelsService;

// Get the service from DI container
$labelsService = \OC::$server->get(LabelsService::class);

// Get labels for a file
$labels = $labelsService->getLabelsForFile($fileId);
// Returns: Label[] entities

// Get labels for multiple files (efficient bulk operation)
$labelsMap = $labelsService->getLabelsForFiles([$fileId1, $fileId2, $fileId3]);
// Returns: array<int, Label[]> - map of fileId => labels

// Check if a file has a specific label
$hasLabel = $labelsService->hasLabel($fileId, 'sensitive');
// Returns: bool

// Check label with specific value
$isSensitive = $labelsService->hasLabel($fileId, 'sensitive', 'true');
// Returns: bool

// Find all files with a label
$fileIds = $labelsService->findFilesByLabel('category', 'work');
// Returns: int[] - file IDs (only accessible to current user)

// Set a label
$label = $labelsService->setLabel($fileId, 'category', 'work');
// Returns: Label entity

// Set multiple labels
$labels = $labelsService->setLabels($fileId, [
    'category' => 'work',
    'priority' => 'high'
]);
// Returns: Label[]

// Delete a label
$deleted = $labelsService->deleteLabel($fileId, 'category');
// Returns: bool
```

### Frontend Event Bus

```javascript
import { subscribe } from '@nextcloud/event-bus'

// Listen for label changes
subscribe('files_labels:label-changed', ({ fileId, labels }) => {
    // Update your app's state
    console.log(`File ${fileId} labels changed:`, labels)

    // Check if file is now marked as sensitive
    if (labels.sensitive === 'true') {
        // Hide preview, etc.
    }
})
```

### Example: File Spoilers App

The File Spoilers app demonstrates how to build on File Labels:

1. Uses `LabelsService.hasLabel()` to check if files have spoiler labels
2. Listens to `files_labels:label-changed` event to update preview visibility
3. Provides UI to configure which labels trigger spoiler behavior
4. Overrides preview rendering based on label presence

See: https://github.com/your-repo/nextcloud-files-spoilers

## Gotchas and Important Considerations

### 1. Labels are User-Specific

**Critical:** Labels are NOT shared between users. Each user has their own independent set of labels for each file.

Example:
- User A labels file.txt as `sensitive=true`
- User B sees no labels on file.txt (they have their own empty label set)

This is by design for privacy and personal organization.

### 2. Permission Checks Return 404

When a user doesn't have permission to read/write a file, the API returns 404 (not 403). This prevents information leakage about file existence.

### 3. WebDAV Caching is Aggressive

The `LabelsPlugin` caches labels at the request level. If you modify labels via direct database operations (bypassing the service), the cache won't invalidate automatically.

**Always use `LabelsService`** to modify labels, never direct database operations.

### 4. Label Keys are Case-Sensitive (but must be lowercase)

The validation enforces lowercase keys, but the database comparison is case-sensitive. Don't try to work around this - always use lowercase keys.

### 5. Frontend Rebuilds Required

Changes to `.vue` files require rebuilding:
```bash
npm run build
```

Then hard refresh the browser (Cmd+Shift+R). Soft refresh won't pick up JavaScript changes.

### 6. Event Bus is Frontend-Only

The `files_labels:label-changed` event only works in the frontend. For backend inter-app communication, use the PHP service directly.

### 7. Bulk Operations Have Limits

`LabelsController.bulk()` limits to 1000 file IDs per request to prevent abuse. If you need more, make multiple requests.

### 8. Cleanup is Automatic

Labels are automatically deleted when:
- File is deleted (`FileDeletedListener`)
- User is deleted (`UserDeletedListener`)

You don't need to handle cleanup in consuming apps.

### 9. Test Coverage is Extensive

Before making changes, run the test suite. There are 100+ tests covering edge cases. If you break something, the tests will tell you.

### 10. Use Podman, Not Docker

This project uses **Podman** for containers:
```bash
podman-compose up -d     # Correct
docker-compose up -d     # Wrong - will fail on this system
```

## Configuration Files

### package.json

Defines npm dependencies and scripts. Key dependencies:
- Vue 2.7 (Nextcloud 28-31 requirement)
- `@nextcloud/vue` for UI components
- Webpack 5 for bundling

### webpack.config.js

Build configuration:
- Entry: `src/main.js`
- Output: `js/files_labels-main.js`
- Vue loader, Babel transpilation, SCSS support

### composer.json

PHP dependencies (development only):
- `nextcloud/ocp` for Nextcloud platform interfaces
- `phpunit/phpunit` for testing

### appinfo/info.xml

App metadata:
- ID: `files_labels`
- Nextcloud version: 28-31
- PHP version: 8.1+
- Loads: `js/files_labels-main.js`

### appinfo/routes.php

OCS API routes (5 endpoints):
- GET `/labels/{fileId}` - get labels
- POST `/labels/bulk` - bulk get
- PUT `/labels/{fileId}/{key}` - set label
- PUT `/labels/{fileId}` - bulk set
- DELETE `/labels/{fileId}/{key}` - delete label

## Resources

- **README.md**: User-facing documentation and API examples
- **FRONTEND.md**: Frontend architecture details
- **QUICKSTART.md**: Quick start guide for developers
- **TESTING.md**: Comprehensive testing checklist
- **UI_IMPLEMENTATION.md**: UI implementation summary

## Support and Debugging

### Common Issues

**Tab doesn't appear:**
1. Verify `npm run build` completed successfully
2. Check `js/files_labels-main.js` exists
3. Hard refresh browser
4. Check browser console for errors
5. Verify app is enabled: `php occ app:list | grep files_labels`

**API returns 404:**
- User doesn't have permission to file
- File doesn't exist
- App not enabled

**Labels not saving:**
- Check Nextcloud logs for permission errors
- Verify file is not read-only
- Check network tab for API response

**Build errors:**
```bash
# Clean and rebuild
rm -rf node_modules package-lock.json js/
npm install
npm run build
```

## Development Workflow

1. Make changes to code
2. For backend: Write/update tests
3. For frontend: Run `npm run dev` (watch mode)
4. Test locally with Podman environment
5. Run test suite
6. Commit changes
7. Deploy to Nextcloud instance

## License

AGPL-3.0-or-later
