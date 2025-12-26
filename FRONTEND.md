# Frontend Implementation

This document describes the frontend UI implementation for the Files Labels app.

## Overview

The frontend provides a sidebar tab in the Nextcloud Files app that allows users to view, add, and delete labels for files.

## Components

### Main Entry Point (`src/main.js`)

Registers the sidebar tab with the Files app using the `OCA.Files.Sidebar.registerTab()` API. The tab:
- Shows only for files (not folders)
- Uses a tag icon
- Mounts the Vue component when opened

### Sidebar Component (`src/views/LabelsSidebarTab.vue`)

A Vue.js component that:
- Loads labels for the current file using the OCS API
- Displays labels in a clean, user-friendly list
- Allows adding new key-value labels
- Allows deleting existing labels
- Shows loading and error states
- Uses Nextcloud Vue components for consistency

## Features

### View Labels
- Displays all labels for the selected file
- Shows key-value pairs with clear formatting
- Empty state when no labels exist

### Add Labels
- Form with separate inputs for key and value
- Validation:
  - Both key and value are required
  - Maximum lengths enforced (key: 255, value: 4000)
  - Prevents duplicate keys
- Success/error notifications

### Delete Labels
- Delete button for each label
- Confirmation via Nextcloud dialogs
- Success/error notifications

## Build Process

```bash
# Development build with watch mode
npm run dev

# Production build (minified)
npm run build
```

Output: `js/files_labels-main.js` (loaded automatically by Nextcloud)

## API Integration

The component uses:
- `@nextcloud/axios` for HTTP requests
- `@nextcloud/router` for URL generation
- `@nextcloud/dialogs` for notifications

API endpoints:
- `GET /ocs/v2.php/apps/files_labels/api/v1/labels/{fileId}` - Get labels
- `PUT /ocs/v2.php/apps/files_labels/api/v1/labels/{fileId}/{key}` - Set label
- `DELETE /ocs/v2.php/apps/files_labels/api/v1/labels/{fileId}/{key}` - Delete label

## Testing

1. Build the frontend: `npm run build`
2. Enable the app in Nextcloud: `php occ app:enable files_labels`
3. Navigate to Files app
4. Select a file
5. Click the "Labels" tab in the sidebar

## Styling

The component uses:
- Scoped SCSS for component-specific styles
- Nextcloud CSS variables for theming consistency
- Responsive layout that works on all screen sizes
