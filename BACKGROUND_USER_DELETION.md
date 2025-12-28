# Background User Deletion - Planning Document

This document outlines the design for moving user label deletion from synchronous
inline processing to a background job system. This change is necessary to prevent
blocking user deletion when users have large numbers of labels.

## 1. Current Behavior Analysis

### How User Deletion Currently Triggers Label Cleanup

When a Nextcloud user is deleted, the following sequence occurs:

```
User Deletion Request
        |
        v
+------------------+
| IUserManager     |
| ->delete(user)   |
+------------------+
        |
        v (EventDispatcher)
+----------------------+
| UserDeletedEvent     |
| dispatched           |
+----------------------+
        |
        v
+------------------------+
| UserDeletedListener    |
| (files_labels app)     |
+------------------------+
        |
        v
+------------------------+
| LabelMapper            |
| ->deleteByUser($uid)   |
+------------------------+
        |
        v
Single DELETE SQL Query:
DELETE FROM file_labels WHERE user_id = :userId
```

### Event/Hook Used

- **Event Class:** `OCP\User\Events\UserDeletedEvent`
- **Listener:** `OCA\FilesLabels\Listener\UserDeletedListener`
- **Registration:** `Application::register()` via `$context->registerEventListener()`
- **Mapper Method:** `LabelMapper::deleteByUser(string $userId)`

### Current SQL Operation

```sql
DELETE FROM oc_file_labels WHERE user_id = ?
```

This is a single unbounded DELETE statement with no LIMIT clause.

### What Happens with 100K Labels?

With 100,000 labels for a single user:

1. **Database Lock Duration:**
   - The DELETE acquires row locks on all 100K rows
   - With InnoDB, this can cause lock contention with other operations
   - Estimated duration: 5-30 seconds depending on hardware

2. **Request Blocking:**
   - The user deletion HTTP request blocks until completion
   - PHP worker is occupied for the entire duration
   - Risk of timeout (often 30s or 60s in production)

3. **User Experience:**
   - Admin sees "spinning wheel" for extended period
   - Risk of timeout error displayed to admin
   - User may be partially deleted if operation times out

4. **Database Index Impact:**
   - The `file_labels_user_key` index on `(user_id, label_key)` enables efficient WHERE clause
   - However, 100K row deletions still require significant I/O
   - Index maintenance during delete adds overhead

### Performance Test Results (Expected)

Based on the test created in `tests/Integration/UserDeletionPerformanceTest.php`:

| Label Count | Expected Time | Notes |
|------------|---------------|-------|
| 1,000 | < 1 second | Acceptable for inline |
| 10,000 | 1-3 seconds | Borderline |
| 50,000 | 5-15 seconds | Too slow for inline |
| 100,000 | 10-30 seconds | Unacceptable for inline |

## 2. Background Job Design

### Nextcloud Background Job Types

Nextcloud provides several job types in `OCP\BackgroundJob`:

| Type | Use Case | Our Fit |
|------|----------|---------|
| `Job` | Manual scheduling only | No - need auto |
| `TimedJob` | Periodic execution | No - need immediate |
| `QueuedJob` | Run once, soon as possible | **Yes** |

### Proposed: DeleteUserLabelsJob

```php
namespace OCA\FilesLabels\BackgroundJob;

use OCA\FilesLabels\Db\LabelMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class DeleteUserLabelsJob extends QueuedJob {
    private const BATCH_SIZE = 10000;
    private const MAX_EXECUTION_TIME = 60; // seconds

    public function __construct(
        ITimeFactory $time,
        private LabelMapper $mapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $userId = $argument['userId'];
        $this->deleteLabelsInBatches($userId);
    }

    private function deleteLabelsInBatches(string $userId): void {
        $startTime = time();
        $totalDeleted = 0;

        do {
            $deleted = $this->mapper->deleteByUserBatch($userId, self::BATCH_SIZE);
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->logger->debug('Deleted {count} labels for user {userId}', [
                    'app' => 'files_labels',
                    'count' => $deleted,
                    'userId' => $userId,
                    'totalDeleted' => $totalDeleted,
                ]);
            }

            // Prevent indefinite execution
            if ((time() - $startTime) > self::MAX_EXECUTION_TIME) {
                $this->logger->warning(
                    'Label deletion time limit reached for user {userId}, will continue in next job',
                    ['app' => 'files_labels', 'userId' => $userId]
                );
                // Re-queue to continue later
                $this->requeue($userId);
                return;
            }

            // Small delay between batches to reduce database pressure
            usleep(10000); // 10ms

        } while ($deleted > 0);

        $this->logger->info('Completed deleting {total} labels for user {userId}', [
            'app' => 'files_labels',
            'total' => $totalDeleted,
            'userId' => $userId,
        ]);
    }

    private function requeue(string $userId): void {
        // Job will be re-added by the caller if needed
        // Or use IJobList to add another instance
    }
}
```

### Batch Size Rationale

**10,000 labels per batch** was chosen because:

1. **Lock Duration:** Deleting 10K rows takes ~1-3 seconds
2. **Memory:** Minimal memory footprint (no entity loading)
3. **Checkpointing:** Natural checkpoint for progress tracking
4. **Recovery:** If job fails, minimal work needs to be redone

### Delay Between Batches

**10ms delay** between batches:

- Allows other database operations to proceed
- Prevents sustained 100% database utilization
- With 100K labels: 10 batches x 10ms = 100ms total overhead
- Negligible impact on total deletion time

### Progress Tracking Options

**Option A: Log-based (Simple)**
- Log each batch completion
- No database state needed
- Suitable for most cases

**Option B: Database Table (Complex)**
```sql
CREATE TABLE file_labels_deletion_progress (
    user_id VARCHAR(64) PRIMARY KEY,
    total_labels INT,
    deleted_labels INT,
    started_at DATETIME,
    updated_at DATETIME,
    status ENUM('pending', 'running', 'completed', 'failed')
);
```
- Enables admin visibility
- Supports resume after server restart
- More complex to maintain

**Recommendation:** Start with Option A, add Option B only if admin visibility is required.

## 3. Implementation Steps

### Step 1: Add Batch Deletion Method to LabelMapper

```php
// In lib/Db/LabelMapper.php

/**
 * Delete labels for a user in batches.
 *
 * @param string $userId The user ID
 * @param int $limit Maximum number of labels to delete
 * @return int Number of labels deleted
 */
public function deleteByUserBatch(string $userId, int $limit = 10000): int {
    // First, get IDs to delete (needed for accurate count)
    $qb = $this->db->getQueryBuilder();
    $qb->select('id')
        ->from($this->getTableName())
        ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
        ->setMaxResults($limit);

    $result = $qb->executeQuery();
    $ids = [];
    while ($row = $result->fetch()) {
        $ids[] = (int)$row['id'];
    }
    $result->closeCursor();

    if (empty($ids)) {
        return 0;
    }

    // Delete by IDs
    $deleteQb = $this->db->getQueryBuilder();
    $deleteQb->delete($this->getTableName())
        ->where($deleteQb->expr()->in(
            'id',
            $deleteQb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
        ));
    $deleteQb->executeStatement();

    return count($ids);
}
```

### Step 2: Create DeleteUserLabelsJob

Create file: `lib/BackgroundJob/DeleteUserLabelsJob.php`

```php
<?php

declare(strict_types=1);

namespace OCA\FilesLabels\BackgroundJob;

use OCA\FilesLabels\Db\LabelMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class DeleteUserLabelsJob extends QueuedJob {
    private const BATCH_SIZE = 10000;
    private const MAX_EXECUTION_TIME = 60;

    public function __construct(
        ITimeFactory $time,
        private LabelMapper $mapper,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        if (!isset($argument['userId'])) {
            $this->logger->error('DeleteUserLabelsJob missing userId argument');
            return;
        }

        $userId = $argument['userId'];
        $this->logger->info('Starting label deletion for user {userId}', [
            'app' => 'files_labels',
            'userId' => $userId,
        ]);

        $startTime = time();
        $totalDeleted = 0;

        do {
            $deleted = $this->mapper->deleteByUserBatch($userId, self::BATCH_SIZE);
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->logger->debug('Deleted batch of {count} labels for user {userId}', [
                    'app' => 'files_labels',
                    'count' => $deleted,
                    'userId' => $userId,
                    'totalSoFar' => $totalDeleted,
                ]);
            }

            // Check execution time
            if ((time() - $startTime) > self::MAX_EXECUTION_TIME && $deleted > 0) {
                $this->logger->info(
                    'Label deletion pausing after {total} labels for user {userId}, scheduling continuation',
                    ['app' => 'files_labels', 'total' => $totalDeleted, 'userId' => $userId]
                );

                // Re-queue to continue
                $this->jobList->add(self::class, ['userId' => $userId]);
                return;
            }

            // Small delay to reduce database pressure
            if ($deleted > 0) {
                usleep(10000); // 10ms
            }

        } while ($deleted > 0);

        $this->logger->info('Completed deleting {total} labels for user {userId}', [
            'app' => 'files_labels',
            'total' => $totalDeleted,
            'userId' => $userId,
        ]);
    }
}
```

### Step 3: Modify UserDeletedListener

```php
<?php

declare(strict_types=1);

namespace OCA\FilesLabels\Listener;

use OCA\FilesLabels\BackgroundJob\DeleteUserLabelsJob;
use OCA\FilesLabels\Db\LabelMapper;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener to clean up labels when a user is deleted.
 *
 * For small label counts, deletion happens inline.
 * For large counts, a background job is queued.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    private const INLINE_THRESHOLD = 1000;

    public function __construct(
        private LabelMapper $mapper,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }

        $user = $event->getUser();
        $userId = $user->getUID();

        try {
            $labelCount = $this->mapper->countByUser($userId);

            if ($labelCount <= self::INLINE_THRESHOLD) {
                // Small enough to delete inline
                $this->mapper->deleteByUser($userId);
                $this->logger->info('Deleted {count} labels inline for user {userId}', [
                    'app' => 'files_labels',
                    'count' => $labelCount,
                    'userId' => $userId,
                ]);
            } else {
                // Queue background job for large deletions
                $this->jobList->add(DeleteUserLabelsJob::class, ['userId' => $userId]);
                $this->logger->info('Queued background deletion of {count} labels for user {userId}', [
                    'app' => 'files_labels',
                    'count' => $labelCount,
                    'userId' => $userId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle label cleanup for user {userId}: {error}', [
                'app' => 'files_labels',
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);

            // On error, queue background job as fallback
            try {
                $this->jobList->add(DeleteUserLabelsJob::class, ['userId' => $userId]);
            } catch (\Exception $e2) {
                $this->logger->error('Failed to queue label cleanup job: {error}', [
                    'app' => 'files_labels',
                    'error' => $e2->getMessage(),
                ]);
            }
        }
    }
}
```

### Step 4: Add countByUser to LabelMapper

```php
// In lib/Db/LabelMapper.php

/**
 * Count labels for a user.
 *
 * @param string $userId The user ID
 * @return int Number of labels
 */
public function countByUser(string $userId): int {
    $qb = $this->db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*)'))
        ->from($this->getTableName())
        ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

    $result = $qb->executeQuery();
    $count = (int)$result->fetchOne();
    $result->closeCursor();

    return $count;
}
```

### Step 5: Register Background Job (Optional)

For QueuedJob, no registration in `info.xml` is needed. The job is added to the
job list dynamically when user deletion is triggered.

If you want the job to also run on app enable (for cleanup of orphaned labels),
add to `appinfo/info.xml`:

```xml
<background-jobs>
    <job>OCA\FilesLabels\BackgroundJob\DeleteUserLabelsJob</job>
</background-jobs>
```

## 4. Edge Cases and Error Handling

### Job Failure

**Scenario:** Background job fails mid-execution (server restart, OOM, etc.)

**Handling:**
1. Job is marked as failed in oc_jobs table
2. Labels remain partially deleted
3. On next user lookup/cleanup, the job can be re-queued
4. The job is idempotent - running again just continues where it left off

### Partial Deletion

**Scenario:** Some labels deleted before failure

**Handling:**
- Not a problem - job will continue deleting remaining labels
- No state corruption possible (DELETE is atomic per row)

### Re-queued While Running

**Scenario:** Admin deletes user again while job is running

**Handling:**
- `IJobList::add()` with same arguments is idempotent
- Only one job instance runs at a time
- The running job will complete normally

### User Recreation

**Scenario:** Admin deletes user, then creates new user with same UID

**Handling:**
- Very unlikely in practice
- Background job would delete the new user's labels
- **Mitigation:** Job should verify user doesn't exist before deleting

```php
protected function run($argument): void {
    $userId = $argument['userId'];

    // Safety check: don't delete if user exists
    $userManager = \OC::$server->get(IUserManager::class);
    if ($userManager->userExists($userId)) {
        $this->logger->warning(
            'Skipping label deletion for user {userId} - user exists',
            ['app' => 'files_labels', 'userId' => $userId]
        );
        return;
    }

    // Proceed with deletion...
}
```

### Database Deadlocks

**Scenario:** Concurrent operations cause deadlock

**Handling:**
- InnoDB automatically detects and resolves deadlocks
- The DELETE will be retried by the database
- If persistent, reduce batch size or add longer delays

## 5. Testing Strategy

### Unit Tests for Background Job

```php
// tests/Unit/BackgroundJob/DeleteUserLabelsJobTest.php

class DeleteUserLabelsJobTest extends TestCase {
    public function testRunDeletesLabelsInBatches(): void {
        $mapper = $this->createMock(LabelMapper::class);
        $mapper->expects($this->exactly(3))
            ->method('deleteByUserBatch')
            ->with('testuser', 10000)
            ->willReturnOnConsecutiveCalls(10000, 10000, 5000, 0);

        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->never())->method('add');

        $job = new DeleteUserLabelsJob($time, $mapper, $jobList, $logger);
        $job->run(['userId' => 'testuser']);
    }

    public function testRunRequeuesOnTimeout(): void {
        // Mock time-based behavior
        $mapper = $this->createMock(LabelMapper::class);
        $mapper->method('deleteByUserBatch')
            ->willReturn(10000); // Always more to delete

        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())
            ->method('add')
            ->with(DeleteUserLabelsJob::class, ['userId' => 'testuser']);

        $job = new DeleteUserLabelsJob($time, $mapper, $jobList, $logger);
        // Would need to mock time or reduce timeout for testing
    }

    public function testRunHandlesMissingUserId(): void {
        $mapper = $this->createMock(LabelMapper::class);
        $mapper->expects($this->never())->method('deleteByUserBatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $job = new DeleteUserLabelsJob($time, $mapper, $jobList, $logger);
        $job->run([]); // No userId
    }
}
```

### Integration Tests

```php
// tests/Integration/BackgroundJobIntegrationTest.php

/**
 * @group DB
 */
class BackgroundJobIntegrationTest extends TestCase {
    public function testDeleteUserLabelsJobCompletesSuccessfully(): void {
        // Setup: Create user and labels
        $this->createLabels(15000);

        // Execute job
        $job = \OC::$server->get(DeleteUserLabelsJob::class);
        $job->run(['userId' => self::TEST_USER]);

        // Verify: All labels deleted
        $remaining = $this->countLabels();
        $this->assertEquals(0, $remaining);
    }

    public function testListenerQueuesJobForLargeCount(): void {
        // Setup: Create user with many labels
        $this->createLabels(5000);

        // Trigger listener
        $listener = \OC::$server->get(UserDeletedListener::class);
        $event = new UserDeletedEvent($this->testUser);
        $listener->handle($event);

        // Verify: Job was queued
        $jobList = \OC::$server->get(IJobList::class);
        $hasJob = $jobList->has(DeleteUserLabelsJob::class, ['userId' => self::TEST_USER]);
        $this->assertTrue($hasJob);
    }

    public function testListenerDeletesInlineForSmallCount(): void {
        // Setup: Create user with few labels
        $this->createLabels(100);

        // Trigger listener
        $listener = \OC::$server->get(UserDeletedListener::class);
        $event = new UserDeletedEvent($this->testUser);
        $listener->handle($event);

        // Verify: Labels deleted immediately, no job queued
        $remaining = $this->countLabels();
        $this->assertEquals(0, $remaining);

        $jobList = \OC::$server->get(IJobList::class);
        $hasJob = $jobList->has(DeleteUserLabelsJob::class, ['userId' => self::TEST_USER]);
        $this->assertFalse($hasJob);
    }
}
```

### Verifying Cleanup Completed

**Manual Verification:**
```bash
# Check if any labels remain for deleted user
occ db:convert-type --dry-run
occ db:execute "SELECT COUNT(*) FROM oc_file_labels WHERE user_id = 'deleted_user'"

# Check job queue
occ background-job:list | grep DeleteUserLabelsJob
```

**Automated Verification (Admin Panel):**
- Could add an admin settings section showing deletion progress
- Show count of orphaned labels (labels for non-existent users)

**Cron-based Cleanup:**
- A periodic TimedJob could scan for orphaned labels and clean them up
- Serves as a safety net for any missed deletions

## 6. Migration Path

### Phase 1: Add Background Job (Non-breaking)

1. Add `DeleteUserLabelsJob` class
2. Add `deleteByUserBatch()` and `countByUser()` to mapper
3. Keep existing `UserDeletedListener` behavior unchanged
4. Add tests

### Phase 2: Modify Listener (Breaking change for large users)

1. Update `UserDeletedListener` to use threshold-based logic
2. Users with < 1000 labels: inline deletion (no change)
3. Users with >= 1000 labels: background job
4. Add monitoring/logging

### Phase 3: Cleanup and Polish

1. Add admin visibility (optional)
2. Add orphaned label cleanup job
3. Performance tuning based on production metrics

## 7. Alternative Approaches Considered

### A: Chunked Inline Deletion

**Approach:** Delete in chunks within the same request
**Pros:** Simpler, no background job infrastructure
**Cons:** Still blocks request, risk of timeout
**Verdict:** Rejected - doesn't solve the core problem

### B: Soft Delete

**Approach:** Mark labels as deleted, clean up later
**Pros:** Instant "deletion", no blocking
**Cons:** Requires schema change, query changes, storage overhead
**Verdict:** Rejected - too complex for this use case

### C: Foreign Key Cascade

**Approach:** Use database foreign key with ON DELETE CASCADE
**Pros:** Database handles cleanup automatically
**Cons:** Nextcloud doesn't use FKs, user table is in different schema, would need trigger
**Verdict:** Rejected - doesn't fit Nextcloud architecture

### D: Event-Driven Queue (Redis/RabbitMQ)

**Approach:** Use external message queue for job processing
**Pros:** More robust, better scaling
**Cons:** External dependency, overkill for this use case
**Verdict:** Rejected - too complex for an app-level solution

## 8. Summary

The recommended approach uses Nextcloud's built-in QueuedJob system with:

1. **Threshold-based routing:** Small deletions inline, large deletions via job
2. **Batched deletion:** 10,000 labels per batch with 10ms delays
3. **Self-healing:** Job re-queues if execution time exceeded
4. **Idempotent design:** Safe to run multiple times
5. **Comprehensive logging:** Track progress and issues

This approach:
- Maintains backward compatibility for small label counts
- Prevents user deletion timeouts
- Uses existing Nextcloud infrastructure
- Requires minimal new code
- Is easy to test and maintain
