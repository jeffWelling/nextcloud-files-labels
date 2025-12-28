# Pagination Implementation Plan for File Labels

## Executive Summary

This document outlines a comprehensive plan to add pagination support to the File Labels app. The goal is to prevent memory exhaustion when dealing with large datasets (files with many labels, or queries returning thousands of files).

## Current Architecture Analysis

### Where Labels Are Fetched in Bulk

1. **LabelMapper.php** - Database layer
   - `findByFilesAndUser(array $fileIds, string $userId)` - Fetches all labels for multiple files with no limit
   - `findFilesByLabel(string $userId, string $key, ?string $value)` - Returns ALL file IDs matching a label

2. **LabelsService.php** - Business logic layer
   - `getLabelsForFiles(array $fileIds)` - Delegates to mapper without pagination
   - `findFilesByLabel(string $key, ?string $value)` - Returns all matching file IDs

3. **LabelsPlugin.php** - WebDAV integration
   - `preloadCollection()` - Fetches labels for ALL files in a directory at once
   - Uses `CappedMemoryCache` but can still overflow with large directories

4. **LabelsController.php** - REST API
   - `bulk()` - Limited to 1000 file IDs per request (hard limit, no pagination)
   - `index()` - Returns all labels for a single file (typically safe)

### Memory Exhaustion Scenarios

| Scenario | Current Behavior | Risk Level |
|----------|------------------|------------|
| Directory with 10K+ files | All labels loaded into CappedMemoryCache | HIGH |
| User has 50K+ labeled files | `findFilesByLabel()` returns all IDs | HIGH |
| File with 1K+ labels | All labels loaded at once | MEDIUM |
| Bulk API with 1000 files | All labels fetched in one query | MEDIUM |

### WebDAV PROPFIND Considerations

WebDAV PROPFIND responses are inherently non-paginated - clients expect all requested properties for all requested resources in a single response. However, we can:

1. **Limit preloading scope** - Only preload N files at a time in directory listings
2. **Streaming responses** - Sabre supports chunked responses for large result sets
3. **Accept limitations** - For massive directories, clients should use depth=0 and fetch individually

---

## Implementation Plan

### Phase 1: Database Layer Changes

#### 1.1 Add Paginated Query Methods to LabelMapper

```php
<?php
// lib/Db/LabelMapper.php

/**
 * Paginated result wrapper
 */
class PaginatedResult {
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $offset,
        public readonly int $limit,
        public readonly bool $hasMore,
    ) {}
}

class LabelMapper extends QBMapper {
    // ... existing code ...

    /**
     * Get labels for a file with pagination
     *
     * @return PaginatedResult<Label>
     */
    public function findByFileAndUserPaginated(
        int $fileId,
        string $userId,
        int $limit = 100,
        int $offset = 0
    ): PaginatedResult {
        // Count query
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('*'))
            ->from($this->getTableName())
            ->where($countQb->expr()->eq('file_id', $countQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($countQb->expr()->eq('user_id', $countQb->createNamedParameter($userId)));

        $result = $countQb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        // Data query with pagination
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('label_key', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $labels = $this->findEntities($qb);

        return new PaginatedResult(
            items: $labels,
            total: $total,
            offset: $offset,
            limit: $limit,
            hasMore: ($offset + count($labels)) < $total
        );
    }

    /**
     * Get labels for multiple files with per-file limit
     *
     * This is for bulk operations where we want some labels per file
     * but not necessarily all of them (e.g., directory previews)
     *
     * @param int[] $fileIds
     * @param int $perFileLimit Max labels per file (0 = unlimited)
     * @return array<int, array{labels: Label[], hasMore: bool}>
     */
    public function findByFilesAndUserLimited(
        array $fileIds,
        string $userId,
        int $perFileLimit = 50
    ): array {
        if (empty($fileIds)) {
            return [];
        }

        // Use window function if supported, otherwise fall back to simple query
        // Simple approach: fetch all then truncate (for now)
        // TODO: Use ROW_NUMBER() OVER (PARTITION BY file_id) for databases that support it

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('file_id', 'ASC')
            ->addOrderBy('label_key', 'ASC');

        $labels = $this->findEntities($qb);

        // Group and limit per file
        $result = [];
        foreach ($fileIds as $fileId) {
            $result[$fileId] = ['labels' => [], 'hasMore' => false];
        }

        $counts = [];
        foreach ($labels as $label) {
            $fid = $label->getFileId();
            if (!isset($counts[$fid])) {
                $counts[$fid] = 0;
            }

            if ($perFileLimit === 0 || $counts[$fid] < $perFileLimit) {
                $result[$fid]['labels'][] = $label;
                $counts[$fid]++;
            } else {
                $result[$fid]['hasMore'] = true;
            }
        }

        return $result;
    }

    /**
     * Find files by label with pagination (cursor-based)
     *
     * Uses cursor-based pagination for stability during iteration.
     * Cursor is the last seen file_id.
     *
     * @return PaginatedResult<int> File IDs
     */
    public function findFilesByLabelPaginated(
        string $userId,
        string $key,
        ?string $value = null,
        int $limit = 100,
        ?int $afterFileId = null
    ): PaginatedResult {
        // Count query (expensive but needed for UI)
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('*'))
            ->from($this->getTableName())
            ->where($countQb->expr()->eq('user_id', $countQb->createNamedParameter($userId)))
            ->andWhere($countQb->expr()->eq('label_key', $countQb->createNamedParameter($key)));

        if ($value !== null) {
            $countQb->andWhere($countQb->expr()->eq('label_value', $countQb->createNamedParameter($value)));
        }

        $result = $countQb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        // Data query with cursor
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('label_key', $qb->createNamedParameter($key)));

        if ($value !== null) {
            $qb->andWhere($qb->expr()->eq('label_value', $qb->createNamedParameter($value)));
        }

        if ($afterFileId !== null) {
            $qb->andWhere($qb->expr()->gt('file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('file_id', 'ASC')
            ->setMaxResults($limit + 1); // Fetch one extra to detect hasMore

        $result = $qb->executeQuery();
        $fileIds = [];
        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        $hasMore = count($fileIds) > $limit;
        if ($hasMore) {
            array_pop($fileIds); // Remove the extra item
        }

        // Calculate offset for response (approximate for cursor-based)
        $offset = $afterFileId !== null ? -1 : 0; // -1 indicates cursor mode

        return new PaginatedResult(
            items: $fileIds,
            total: $total,
            offset: $offset,
            limit: $limit,
            hasMore: $hasMore
        );
    }

    /**
     * Count labels for a specific file
     */
    public function countByFileAndUser(int $fileId, string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Count files with a specific label
     */
    public function countFilesByLabel(string $userId, string $key, ?string $value = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('label_key', $qb->createNamedParameter($key)));

        if ($value !== null) {
            $qb->andWhere($qb->expr()->eq('label_value', $qb->createNamedParameter($value)));
        }

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}
```

#### 1.2 Create PaginatedResult Class

```php
<?php
// lib/Db/PaginatedResult.php

declare(strict_types=1);

namespace OCA\FilesLabels\Db;

/**
 * Generic paginated result wrapper
 *
 * @template T
 */
class PaginatedResult implements \JsonSerializable {
    /**
     * @param T[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $offset,
        public readonly int $limit,
        public readonly bool $hasMore,
    ) {}

    /**
     * Get the cursor for the next page (for cursor-based pagination)
     * Returns null if there are no more items
     *
     * @return mixed|null
     */
    public function getNextCursor(): mixed {
        if (!$this->hasMore || empty($this->items)) {
            return null;
        }
        $lastItem = end($this->items);

        // Return appropriate cursor based on item type
        if ($lastItem instanceof Label) {
            return $lastItem->getId();
        }
        if (is_int($lastItem)) {
            return $lastItem; // For file ID results
        }
        return null;
    }

    public function jsonSerialize(): array {
        return [
            'items' => $this->items,
            'pagination' => [
                'total' => $this->total,
                'offset' => $this->offset,
                'limit' => $this->limit,
                'hasMore' => $this->hasMore,
                'nextCursor' => $this->getNextCursor(),
            ],
        ];
    }
}
```

---

### Phase 2: Service Layer Changes

#### 2.1 Update LabelsService with Pagination Support

```php
<?php
// lib/Service/LabelsService.php

use OCA\FilesLabels\Db\PaginatedResult;

class LabelsService {
    // ... existing constants and constructor ...

    /**
     * Get labels for a file with pagination
     *
     * @return PaginatedResult<Label>
     * @throws NotPermittedException if user cannot access file
     */
    public function getLabelsForFilePaginated(
        int $fileId,
        int $limit = 100,
        int $offset = 0
    ): PaginatedResult {
        $userId = $this->accessChecker->getCurrentUserId();
        if ($userId === null) {
            throw new NotPermittedException('Not authenticated');
        }

        if (!$this->accessChecker->canRead($fileId)) {
            throw new NotPermittedException('Cannot access file');
        }

        return $this->mapper->findByFileAndUserPaginated($fileId, $userId, $limit, $offset);
    }

    /**
     * Get labels for multiple files with per-file limiting
     *
     * Useful for directory listings where you want a preview of labels
     * but not every single label for every file.
     *
     * @param int[] $fileIds
     * @param int $perFileLimit Max labels to return per file (0 = unlimited)
     * @return array<int, array{labels: Label[], hasMore: bool}>
     */
    public function getLabelsForFilesLimited(
        array $fileIds,
        int $perFileLimit = 50
    ): array {
        $userId = $this->accessChecker->getCurrentUserId();
        if ($userId === null) {
            return [];
        }

        // Filter to accessible files first
        $accessibleIds = $this->accessChecker->filterAccessible($fileIds);
        if (empty($accessibleIds)) {
            return [];
        }

        return $this->mapper->findByFilesAndUserLimited($accessibleIds, $userId, $perFileLimit);
    }

    /**
     * Find all files with a specific label (paginated)
     *
     * @return PaginatedResult<int> File IDs
     */
    public function findFilesByLabelPaginated(
        string $key,
        ?string $value = null,
        int $limit = 100,
        ?int $afterFileId = null
    ): PaginatedResult {
        $userId = $this->accessChecker->getCurrentUserId();
        if ($userId === null) {
            return new PaginatedResult([], 0, 0, $limit, false);
        }

        $result = $this->mapper->findFilesByLabelPaginated(
            $userId,
            $key,
            $value,
            $limit,
            $afterFileId
        );

        // Filter to accessible files (unfortunately loses accurate count)
        // For large result sets, we may need to do this differently
        $accessibleIds = $this->accessChecker->filterAccessible($result->items);

        return new PaginatedResult(
            items: $accessibleIds,
            total: $result->total, // Note: total is pre-filter, may be inaccurate
            offset: $result->offset,
            limit: $result->limit,
            hasMore: $result->hasMore
        );
    }

    /**
     * Count labels for a file
     */
    public function countLabelsForFile(int $fileId): int {
        $userId = $this->accessChecker->getCurrentUserId();
        if ($userId === null) {
            return 0;
        }

        if (!$this->accessChecker->canRead($fileId)) {
            return 0;
        }

        return $this->mapper->countByFileAndUser($fileId, $userId);
    }

    /**
     * Count files with a specific label
     *
     * Note: This returns the total in database, not filtered by access.
     * For accurate accessible count, you'd need to iterate.
     */
    public function countFilesByLabel(string $key, ?string $value = null): int {
        $userId = $this->accessChecker->getCurrentUserId();
        if ($userId === null) {
            return 0;
        }

        return $this->mapper->countFilesByLabel($userId, $key, $value);
    }
}
```

---

### Phase 3: API Changes

#### 3.1 Add Paginated REST Endpoints

```php
<?php
// lib/Controller/LabelsController.php

class LabelsController extends OCSController {
    // ... existing code ...

    /**
     * Get labels for a file with pagination
     *
     * @param int $fileId The file ID
     * @param int $limit Max labels to return (default 100, max 500)
     * @param int $offset Starting offset
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function indexPaginated(int $fileId, int $limit = 100, int $offset = 0): DataResponse {
        // Enforce limits
        $limit = min(max(1, $limit), 500);
        $offset = max(0, $offset);

        try {
            $result = $this->labelsService->getLabelsForFilePaginated($fileId, $limit, $offset);

            // Convert labels to key-value format
            $labels = [];
            foreach ($result->items as $label) {
                $labels[$label->getLabelKey()] = $label->getLabelValue();
            }

            return new DataResponse([
                'labels' => $labels,
                'pagination' => [
                    'total' => $result->total,
                    'offset' => $result->offset,
                    'limit' => $result->limit,
                    'hasMore' => $result->hasMore,
                ],
            ]);
        } catch (NotPermittedException $e) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Search for files by label with pagination
     *
     * @param string $key Label key to search for
     * @param string|null $value Optional value to match
     * @param int $limit Max files to return (default 100, max 1000)
     * @param int|null $cursor File ID cursor for pagination
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function search(
        string $key,
        ?string $value = null,
        int $limit = 100,
        ?int $cursor = null
    ): DataResponse {
        // Enforce limits
        $limit = min(max(1, $limit), 1000);

        try {
            $result = $this->labelsService->findFilesByLabelPaginated(
                $key,
                $value,
                $limit,
                $cursor
            );

            return new DataResponse([
                'fileIds' => $result->items,
                'pagination' => [
                    'total' => $result->total,
                    'limit' => $result->limit,
                    'hasMore' => $result->hasMore,
                    'nextCursor' => $result->getNextCursor(),
                ],
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Enhanced bulk get with per-file limiting
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function bulkLimited(): DataResponse {
        $fileIds = $this->request->getParam('fileIds', []);
        $perFileLimit = $this->request->getParam('perFileLimit', 50);

        if (!is_array($fileIds)) {
            return new DataResponse(['error' => 'fileIds must be an array'], Http::STATUS_BAD_REQUEST);
        }

        if (empty($fileIds)) {
            return new DataResponse([]);
        }

        // Limit request size
        if (count($fileIds) > 1000) {
            return new DataResponse(['error' => 'Too many file IDs (max 1000)'], Http::STATUS_BAD_REQUEST);
        }

        // Limit per-file limit
        $perFileLimit = min(max(0, (int)$perFileLimit), 500);

        $fileIds = array_map('intval', $fileIds);
        $labelsMap = $this->labelsService->getLabelsForFilesLimited($fileIds, $perFileLimit);

        // Convert to response format
        $result = [];
        foreach ($labelsMap as $fileId => $data) {
            $labels = [];
            foreach ($data['labels'] as $label) {
                $labels[$label->getLabelKey()] = $label->getLabelValue();
            }
            $result[$fileId] = [
                'labels' => $labels,
                'hasMore' => $data['hasMore'],
            ];
        }

        return new DataResponse($result);
    }
}
```

#### 3.2 Update Routes

```php
<?php
// appinfo/routes.php

return [
    'ocs' => [
        // Existing routes
        ['name' => 'Labels#bulk', 'url' => '/api/v1/labels/bulk', 'verb' => 'POST'],
        ['name' => 'Labels#index', 'url' => '/api/v1/labels/{fileId}', 'verb' => 'GET'],
        ['name' => 'Labels#set', 'url' => '/api/v1/labels/{fileId}/{key}', 'verb' => 'PUT'],
        ['name' => 'Labels#delete', 'url' => '/api/v1/labels/{fileId}/{key}', 'verb' => 'DELETE'],
        ['name' => 'Labels#bulkSet', 'url' => '/api/v1/labels/{fileId}', 'verb' => 'PUT'],

        // New paginated routes (v2)
        ['name' => 'Labels#indexPaginated', 'url' => '/api/v2/labels/{fileId}', 'verb' => 'GET'],
        ['name' => 'Labels#search', 'url' => '/api/v2/labels/search', 'verb' => 'GET'],
        ['name' => 'Labels#bulkLimited', 'url' => '/api/v2/labels/bulk', 'verb' => 'POST'],
    ],
];
```

---

### Phase 4: WebDAV Changes

WebDAV PROPFIND is inherently non-paginated, but we can add safeguards:

#### 4.1 Limit Preloading Scope

```php
<?php
// lib/DAV/LabelsPlugin.php

class LabelsPlugin extends ServerPlugin {
    // Maximum files to preload labels for
    private const MAX_PRELOAD_FILES = 500;

    // Maximum labels to cache per file
    private const MAX_LABELS_PER_FILE = 100;

    public function preloadCollection(PropFind $propFind, ICollection $collection): void {
        if (!($collection instanceof Directory)) {
            return;
        }

        $path = $collection->getPath();
        if (isset($this->cachedDirectories[$path])) {
            return;
        }

        if ($propFind->getStatus(self::PROPERTY_LABELS) === null) {
            return;
        }

        // Collect file IDs (with limit)
        $fileIds = [(int)$collection->getId()];
        $children = $collection->getChildren();
        $childCount = 0;

        foreach ($children as $child) {
            if ($childCount >= self::MAX_PRELOAD_FILES) {
                // Log warning about large directory
                \OC::$server->getLogger()->warning(
                    'LabelsPlugin: Directory has more than ' . self::MAX_PRELOAD_FILES .
                    ' items, labels will be fetched on-demand for remaining items',
                    ['path' => $path]
                );
                break;
            }

            if ($child instanceof File || $child instanceof Directory) {
                $fileIds[] = (int)$child->getId();
                $childCount++;
            }
        }

        // Bulk fetch labels with per-file limit
        $labelsMap = $this->labelsService->getLabelsForFilesLimited(
            $fileIds,
            self::MAX_LABELS_PER_FILE
        );

        // Cache results
        foreach ($labelsMap as $fileId => $data) {
            $labelData = [];
            foreach ($data['labels'] as $label) {
                $labelData[$label->getLabelKey()] = $label->getLabelValue();
            }
            // Include truncation indicator
            if ($data['hasMore']) {
                $labelData['__truncated'] = true;
            }
            $this->cachedLabels[$fileId] = $labelData;
        }

        // Mark empty results too
        foreach ($fileIds as $fileId) {
            if (!isset($this->cachedLabels[$fileId])) {
                $this->cachedLabels[$fileId] = [];
            }
        }

        $this->cachedDirectories[$path] = true;
    }
}
```

---

### Phase 5: Frontend Changes

#### 5.1 Update LabelsSidebarTab.vue with Lazy Loading

```vue
<!-- src/views/LabelsSidebarTab.vue -->

<template>
    <div class="files-labels-tab">
        <div v-if="loading && !labels.length" class="loading-container">
            <div class="icon-loading" />
            <p>{{ t('files_labels', 'Loading labels...') }}</p>
        </div>

        <div v-else class="labels-content">
            <!-- Labels count header -->
            <div v-if="totalLabels > 0" class="labels-header">
                <span class="labels-count">
                    {{ t('files_labels', '{count} labels', { count: totalLabels }) }}
                </span>
            </div>

            <!-- Existing labels list -->
            <div v-if="Object.keys(labels).length > 0" class="labels-list">
                <h3>{{ t('files_labels', 'Current labels') }}</h3>
                <ul class="label-items">
                    <li v-for="(value, key) in labels" :key="key" class="label-item">
                        <div class="label-display">
                            <span class="label-key">{{ key }}</span>
                            <span class="label-separator">:</span>
                            <span class="label-value">{{ value }}</span>
                        </div>
                        <NcActions>
                            <NcActionButton
                                :aria-label="t('files_labels', 'Delete label')"
                                icon="icon-delete"
                                @click="deleteLabel(key)">
                                {{ t('files_labels', 'Delete') }}
                            </NcActionButton>
                        </NcActions>
                    </li>
                </ul>

                <!-- Load more button -->
                <div v-if="hasMore" class="load-more">
                    <NcButton
                        type="secondary"
                        :disabled="loadingMore"
                        @click="loadMore">
                        <template #icon>
                            <span v-if="loadingMore" class="icon-loading-small" />
                        </template>
                        {{ loadingMore
                            ? t('files_labels', 'Loading...')
                            : t('files_labels', 'Load more ({remaining} remaining)', {
                                remaining: totalLabels - Object.keys(labels).length
                              })
                        }}
                    </NcButton>
                </div>
            </div>

            <div v-else class="empty-content">
                <BookmarkOutline :size="64" class="empty-icon" />
                <p>{{ t('files_labels', 'No labels yet') }}</p>
            </div>

            <!-- Add new label form (unchanged) -->
            <div class="add-label-form">
                <!-- ... existing form code ... -->
            </div>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'

const PAGE_SIZE = 50

export default {
    name: 'LabelsSidebarTab',

    components: {
        NcButton,
        NcActions,
        NcActionButton,
        BookmarkOutline,
    },

    props: {
        fileInfo: {
            type: Object,
            default: () => ({}),
        },
    },

    data() {
        return {
            labels: {},
            loading: false,
            loadingMore: false,
            saving: false,
            error: null,
            newKey: '',
            newValue: '',
            // Pagination state
            totalLabels: 0,
            currentOffset: 0,
            hasMore: false,
        }
    },

    computed: {
        fileId() {
            return this.fileInfo?.id
        },
    },

    watch: {
        fileInfo: {
            immediate: true,
            handler() {
                // Reset pagination state on file change
                this.labels = {}
                this.currentOffset = 0
                this.hasMore = false
                this.totalLabels = 0
                this.loadLabels()
            },
        },
    },

    methods: {
        t,

        async loadLabels() {
            if (!this.fileId) {
                return
            }

            this.loading = true
            this.error = null

            try {
                // Use paginated endpoint (v2)
                const url = generateOcsUrl('apps/files_labels/api/v2/labels/{fileId}', {
                    fileId: this.fileId,
                }) + `?limit=${PAGE_SIZE}&offset=0`

                const response = await axios.get(url)
                const data = response.data.ocs.data

                this.labels = data.labels || {}
                this.totalLabels = data.pagination?.total || Object.keys(this.labels).length
                this.hasMore = data.pagination?.hasMore || false
                this.currentOffset = PAGE_SIZE
            } catch (error) {
                console.error('Failed to load labels:', error)

                // Fallback to v1 endpoint
                try {
                    const url = generateOcsUrl('apps/files_labels/api/v1/labels/{fileId}', {
                        fileId: this.fileId,
                    })
                    const response = await axios.get(url)
                    this.labels = response.data.ocs.data || {}
                    this.totalLabels = Object.keys(this.labels).length
                    this.hasMore = false
                } catch (fallbackError) {
                    this.error = t('files_labels', 'Failed to load labels')
                    showError(t('files_labels', 'Failed to load labels'))
                }
            } finally {
                this.loading = false
            }
        },

        async loadMore() {
            if (!this.fileId || this.loadingMore || !this.hasMore) {
                return
            }

            this.loadingMore = true

            try {
                const url = generateOcsUrl('apps/files_labels/api/v2/labels/{fileId}', {
                    fileId: this.fileId,
                }) + `?limit=${PAGE_SIZE}&offset=${this.currentOffset}`

                const response = await axios.get(url)
                const data = response.data.ocs.data

                // Merge new labels with existing
                this.labels = {
                    ...this.labels,
                    ...(data.labels || {}),
                }

                this.hasMore = data.pagination?.hasMore || false
                this.currentOffset += PAGE_SIZE
            } catch (error) {
                console.error('Failed to load more labels:', error)
                showError(t('files_labels', 'Failed to load more labels'))
            } finally {
                this.loadingMore = false
            }
        },

        // ... rest of existing methods (addLabel, deleteLabel) unchanged ...
    },
}
</script>

<style scoped lang="scss">
/* ... existing styles ... */

.labels-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 0 4px;
}

.labels-count {
    font-size: 12px;
    color: var(--color-text-lighter);
}

.load-more {
    display: flex;
    justify-content: center;
    margin-top: 16px;
    padding: 8px;
}
</style>
```

---

### Phase 6: Edge Cases and Considerations

#### 6.1 Mid-Pagination Data Changes

**Problem:** If labels are added/deleted while a user is paginating, they might miss items or see duplicates.

**Solutions:**

1. **Cursor-Based Pagination (Recommended for findFilesByLabel)**
   - Use `afterFileId` cursor instead of offset
   - Stable even if items are inserted/deleted
   - Already implemented in `findFilesByLabelPaginated`

2. **Optimistic UI Updates**
   - When adding/deleting labels locally, update the local count
   - Don't re-fetch the entire list unless necessary

3. **Versioning (Future Enhancement)**
   ```php
   // Add to file_labels table
   $table->addColumn('version', Types::BIGINT, ['default' => 0]);

   // Increment on any change
   // Return version in pagination response
   // Client can detect changes
   ```

#### 6.2 Files with 100K+ Labels

**Scenario:** A file has an extreme number of labels (e.g., from automated tooling).

**Protections:**

1. **Hard Limit on Labels Per File**
   ```php
   // In LabelsService::setLabel()
   private const MAX_LABELS_PER_FILE = 10000;

   public function setLabel(int $fileId, string $key, string $value): Label {
       // Check existing count
       $count = $this->mapper->countByFileAndUser($fileId, $userId);
       if ($count >= self::MAX_LABELS_PER_FILE) {
           // Check if this is an update (allowed) or new (denied)
           $existing = $this->mapper->findByFileUserAndKey($fileId, $userId, $key);
           if ($existing === null) {
               throw new \RuntimeException(
                   "Maximum labels per file ({self::MAX_LABELS_PER_FILE}) exceeded"
               );
           }
       }
       // ... rest of method
   }
   ```

2. **UI Warning**
   ```javascript
   // In LabelsSidebarTab.vue
   computed: {
       showWarning() {
           return this.totalLabels > 1000
       },
       warningMessage() {
           if (this.totalLabels > 10000) {
               return t('files_labels', 'This file has a very large number of labels. Performance may be affected.')
           }
           if (this.totalLabels > 1000) {
               return t('files_labels', 'This file has many labels.')
           }
           return ''
       }
   }
   ```

3. **Index Optimization**
   ```sql
   -- Add covering index for count queries
   CREATE INDEX file_labels_file_user_count
   ON file_labels (file_id, user_id, id);
   ```

#### 6.3 Memory Limits in PHP

```php
// In long-running operations, batch the work

public function processAllLabelsForUser(string $userId, callable $callback): void {
    $offset = 0;
    $batchSize = 1000;

    do {
        $labels = $this->findAllForUserBatch($userId, $batchSize, $offset);

        foreach ($labels as $label) {
            $callback($label);
        }

        // Free memory
        unset($labels);
        gc_collect_cycles();

        $offset += $batchSize;
    } while (count($labels) === $batchSize);
}
```

---

## Testing Strategy

### Unit Tests

```php
<?php
// tests/Unit/Db/LabelMapperPaginationTest.php

class LabelMapperPaginationTest extends TestCase {
    public function testFindByFileAndUserPaginatedReturnsCorrectPage(): void {
        // Create 25 labels
        // Request page 2 (offset 10, limit 10)
        // Verify returns labels 11-20
        // Verify hasMore = true
        // Verify total = 25
    }

    public function testFindByFileAndUserPaginatedLastPage(): void {
        // Create 25 labels
        // Request page 3 (offset 20, limit 10)
        // Verify returns labels 21-25
        // Verify hasMore = false
    }

    public function testFindFilesByLabelPaginatedCursorStability(): void {
        // Create labels for files 100, 200, 300, 400, 500
        // Fetch first page (afterFileId = null, limit = 2)
        // Verify returns [100, 200]
        // Delete file 150's labels (doesn't exist, no effect)
        // Fetch next page (afterFileId = 200, limit = 2)
        // Verify returns [300, 400]
        // Insert file 250's labels
        // Fetch next page (afterFileId = 400, limit = 2)
        // Verify returns [500] (250 not included - cursor is stable)
    }
}
```

### Integration Tests

```php
<?php
// tests/Integration/PaginationIntegrationTest.php

class PaginationIntegrationTest extends TestCase {
    public function testLargeDirectoryWebDAV(): void {
        // Create 1000 files in a directory
        // Add labels to each
        // PROPFIND the directory
        // Verify response contains limited labels
        // Verify __truncated indicator where appropriate
    }

    public function testApiV2PaginatedEndpoint(): void {
        // Create file with 100 labels
        // GET /api/v2/labels/{fileId}?limit=20&offset=0
        // Verify response format
        // Verify pagination metadata
        // GET subsequent pages
        // Verify all labels retrieved
    }
}
```

---

## Migration Path

### Phase 1: Non-Breaking Changes
1. Add new `PaginatedResult` class
2. Add new paginated methods to `LabelMapper` (alongside existing)
3. Add new methods to `LabelsService` (alongside existing)
4. Add v2 API routes (parallel to v1)
5. Update frontend to prefer v2, fallback to v1

### Phase 2: Optimization
1. Update `LabelsPlugin` to use limited fetching
2. Add hard limits on labels per file
3. Add database indexes

### Phase 3: Deprecation
1. Log warnings for v1 API usage
2. Document migration guide
3. Eventually remove v1 (major version bump)

---

## Configuration Options

Add app config for tunable limits:

```php
<?php
// lib/AppInfo/Application.php

class Application extends App implements IBootstrap {
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 500;
    public const MAX_LABELS_PER_FILE = 10000;
    public const MAX_PRELOAD_FILES = 500;

    // These can be overridden via occ config:app:set
}
```

```bash
# Override defaults
occ config:app:set files_labels max_labels_per_file --value=50000
occ config:app:set files_labels max_preload_files --value=1000
```

---

## Summary

| Component | Change | Priority |
|-----------|--------|----------|
| LabelMapper | Add paginated query methods | HIGH |
| PaginatedResult | New class for typed pagination | HIGH |
| LabelsService | Add paginated service methods | HIGH |
| LabelsController | Add v2 API endpoints | HIGH |
| LabelsPlugin | Limit preloading scope | MEDIUM |
| Frontend | Add lazy loading | MEDIUM |
| Database | Add covering indexes | MEDIUM |
| Hard limits | Max labels per file | MEDIUM |
| Config | Tunable limits | LOW |

This implementation preserves backward compatibility while adding robust pagination support for all data access paths.
