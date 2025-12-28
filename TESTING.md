# Testing Guide for Files Labels App

This document describes the comprehensive testing approach for the Nextcloud Files Labels application, including both backend PHPUnit tests and frontend UI testing.

## Test Suite Structure

```
tests/
├── bootstrap.php                              # Test bootstrap
├── phpunit.xml                                # PHPUnit configuration
├── Integration/                               # Integration tests (future)
└── Unit/                                      # Unit tests
    ├── Controller/
    │   └── LabelsControllerTest.php          # OCS API controller tests
    ├── DAV/
    │   └── LabelsPluginTest.php              # WebDAV plugin tests
    ├── Db/
    │   ├── LabelTest.php                     # Entity tests
    │   └── LabelMapperTest.php               # Database mapper tests
    ├── Listener/
    │   └── SabrePluginAddListenerTest.php    # Event listener tests
    └── Service/
        ├── AccessCheckerTest.php             # Permission checking tests
        └── LabelsServiceTest.php             # Business logic tests
```

## Test Statistics

- **Total Lines of Test Code**: ~2,100 lines
- **Test Files**: 7 unit test files
- **Test Classes**: 7 classes
- **Estimated Test Methods**: 100+ individual test methods

## Running Tests

### Run All Tests

```bash
cd /path/to/nextcloud
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml
```

### Run Specific Test Suite

```bash
# Unit tests only
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml --testsuite unit

# Integration tests only
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml --testsuite integration

# Scalability benchmarks only
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml --group scalability
```

### Run Specific Test Class

```bash
php -f tests/lib/TestRunner.php apps/files_labels/tests/Unit/Service/LabelsServiceTest.php
```

### Run with Coverage

```bash
php -f tests/lib/TestRunner.php --coverage-html coverage apps/files_labels/tests/phpunit.xml
```

## Test Coverage by Component

### 1. LabelTest.php (Entity Tests)

Tests the `Label` entity class:

- ✓ Getter/setter methods for all properties
- ✓ Type conversion (integers, DateTime objects)
- ✓ `toArray()` serialization
- ✓ Null date handling
- ✓ Empty and long values
- ✓ Special characters and Unicode in values
- ✓ Special characters in keys

**Test Methods**: 11

### 2. LabelMapperTest.php (Database Mapper Tests)

Tests the `LabelMapper` database operations:

- ✓ Query builder usage for `findByFileAndUser`
- ✓ Empty array handling in `findByFilesAndUser`
- ✓ Insert operation in `setLabel` (new label)
- ✓ Update operation in `setLabel` (existing label)
- ✓ Delete label success case
- ✓ Delete label not found case
- ✓ `deleteByFile` bulk deletion
- ✓ `deleteByUser` bulk deletion
- ✓ Exception handling for `findByFileUserAndKey`

**Test Methods**: 9

### 3. AccessCheckerTest.php (Permission Checking Tests)

Tests the `AccessChecker` permission system:

- ✓ Get current user ID (authenticated and not)
- ✓ `canRead` with valid access
- ✓ `canRead` without authentication
- ✓ `canRead` file not found
- ✓ `canRead` exception handling
- ✓ `canWrite` with UPDATE permission
- ✓ `canWrite` without authentication
- ✓ `canWrite` without UPDATE permission
- ✓ `canWrite` with no accessible nodes
- ✓ `canWrite` with multiple nodes (first has permission)
- ✓ `canWrite` exception handling
- ✓ `filterAccessible` success case
- ✓ `filterAccessible` without authentication
- ✓ `filterAccessible` exception handling
- ✓ `filterAccessible` empty array

**Test Methods**: 15

### 4. LabelsServiceTest.php (Business Logic Tests)

Tests the `LabelsService` core business logic:

- ✓ Get labels for file (success, not authenticated, no access)
- ✓ Get labels for multiple files (success, not authenticated, no accessible files)
- ✓ Set label (success, not authenticated, no write permission)
- ✓ Key validation (empty, too long, invalid pattern)
- ✓ Valid key patterns (data provider with 7 cases)
- ✓ Invalid key patterns (data provider with 7 cases)
- ✓ Value validation (too long, max length)
- ✓ Set multiple labels (success, validates all first)
- ✓ Delete label (success, not found, not authenticated, no write permission)
- ✓ Find files by label (success, not authenticated)
- ✓ Has label (success, not found, wrong value, not authenticated, no read access, without value check)

**Test Methods**: 27 (including data provider cases)

### 5. LabelsControllerTest.php (OCS Controller Tests)

Tests the `LabelsController` OCS API endpoints:

- ✓ `index()` - Get all labels (success, not permitted, empty labels)
- ✓ `set()` - Set single label (success, empty value, not permitted, invalid argument)
- ✓ `delete()` - Delete label (success, not found, not permitted)
- ✓ `bulkSet()` - Bulk set labels (success, empty labels, invalid type, not permitted, invalid argument)

**Test Methods**: 14

### 6. LabelsPluginTest.php (WebDAV Plugin Tests)

Tests the `LabelsPlugin` DAV integration:

- ✓ Initialize server with event handlers
- ✓ Handle properties for files
- ✓ Handle properties for directories
- ✓ Handle properties when not permitted
- ✓ Handle properties for non-Node objects
- ✓ Preload collection for directory
- ✓ Preload collection when property not requested
- ✓ Preload collection for non-directory
- ✓ Preload collection caching (skip duplicate preload)
- ✓ Handle properties using cache
- ✓ Handle properties with empty labels

**Test Methods**: 11

### 7. SabrePluginAddListenerTest.php (Event Listener Tests)

Tests the `SabrePluginAddListener` event handling:

- ✓ Handle SabrePluginAddEvent correctly
- ✓ Ignore wrong event types
- ✓ Retrieve plugin from container
- ✓ Constructor test

**Test Methods**: 4

## Validation Rules Tested

### Label Key Validation
- **Pattern**: `[a-z0-9_:.-]+` (lowercase alphanumeric, dots, dashes, underscores, colons)
- **Max Length**: 64 characters
- **Cannot be empty**

Valid examples tested:
- `lowercase`
- `with-dash`
- `with_underscore`
- `with.dot`
- `with:colon`
- `numbers123`
- `mix-all_valid.chars:together`

Invalid examples tested:
- `Has Space` (contains space)
- `UPPERCASE` (uppercase letters)
- `special!char` (invalid character)
- `has@symbol` (invalid character)

### Label Value Validation
- **Max Length**: 4,096 characters
- **Can be empty**
- **Supports Unicode and special characters**

## Testing Patterns Used

### 1. Mocking Dependencies
All tests use PHPUnit mocks for dependencies:
```php
$this->mapper = $this->createMock(LabelMapper::class);
$this->accessChecker = $this->createMock(AccessChecker::class);
```

### 2. Testing Both Success and Failure Cases
Every method tests:
- Success path
- Permission denied scenarios
- Not found scenarios
- Invalid input scenarios

### 3. Data Providers
Used for testing multiple similar cases:
```php
/**
 * @dataProvider validKeyProvider
 */
public function testSetLabelValidKeys(string $key): void
```

### 4. Exception Testing
```php
$this->expectException(NotPermittedException::class);
$this->expectExceptionMessage('Not authenticated');
```

### 5. Assertion Coverage
- Type assertions (`assertInstanceOf`, `assertIsArray`)
- Value assertions (`assertEquals`, `assertTrue`)
- Collection assertions (`assertCount`, `assertArrayHasKey`)
- State assertions (`assertEmpty`, `assertNull`)

## Mock Verification

Tests verify:
- Methods are called with correct parameters
- Methods are called the expected number of times
- Methods are never called in certain scenarios (using `expects($this->never())`)

## Edge Cases Covered

1. **Empty collections**: Empty arrays, no labels
2. **Null values**: Null dates, null user session
3. **Boundary conditions**: Max length values (64 chars for keys, 4096 for values)
4. **Special characters**: Unicode, quotes, newlines
5. **Permission scenarios**: Read without write, write without read, no access
6. **Caching**: Cache hits, cache misses, preload optimization
7. **Multiple nodes**: Files accessible through multiple paths

## Integration Tests

The `tests/Integration/` directory contains integration tests that run against a real database and file system within Nextcloud. These tests verify the full stack from service layer to database.

See `tests/Integration/LabelsIntegrationTest.php` for comprehensive integration tests covering:
- Basic CRUD operations
- Multiple labels on files
- Bulk operations
- Search/filter operations
- Validation rules
- Special characters
- Cleanup hooks
- Database mapper operations

## Scalability Benchmarks

**Location**: `tests/Integration/ScalabilityBenchmarkTest.php`

The scalability benchmark suite measures performance of label operations at various scales to identify bottlenecks and ensure the system can handle large numbers of labels.

### Running Benchmarks

```bash
# Run only scalability benchmarks
cd /path/to/nextcloud
php -f tests/lib/TestRunner.php apps/files_labels/tests/phpunit.xml --group scalability
```

These tests are marked with `@group scalability` so they don't run in normal test suites.

### Test Scales

Benchmarks measure operations at the following scales:
- 10 labels
- 100 labels
- 1,000 labels
- 10,000 labels

### Benchmark Tests

1. **Add Labels** (`testBenchmarkAddLabels`)
   - Measures sequential insertion of labels onto a file
   - Tests single-label set operation performance
   - Reports time, memory, and throughput

2. **Fetch Labels** (`testBenchmarkFetchLabels`)
   - Measures retrieval of all labels for a file
   - Tests query performance at scale
   - Reports time, memory, and throughput

3. **Bulk Fetch** (`testBenchmarkBulkFetch`)
   - Measures bulk retrieval for multiple files
   - Tests with 100 files having 10 labels each (1,000 total)
   - Tests with 100 files having 100 labels each (10,000 total)
   - Reports time, memory, and throughput

4. **Delete Labels** (`testBenchmarkDeleteLabels`)
   - Measures sequential deletion of labels
   - Tests cleanup performance
   - Reports time, memory, and throughput

5. **Bulk Set** (`testBenchmarkBulkSet`)
   - Measures batch insertion of multiple labels at once
   - Tests `setLabels()` bulk operation
   - Reports time, memory, and throughput

6. **Update Labels** (`testBenchmarkUpdateLabels`)
   - Measures modification of existing labels
   - Tests update vs insert performance
   - Reports time, memory, and throughput

7. **Find by Label** (`testBenchmarkFindByLabel`)
   - Measures search performance across files
   - Tests index usage and query optimization
   - Scales: 10, 100, 1,000 files
   - Reports time, memory, and throughput

8. **Stress Test** (`testStressSingleFileMaxLabels`)
   - Single file with 50,000 labels
   - Identifies breaking points and memory limits
   - Includes progress indicators
   - Comprehensive verification

### Benchmark Output

Results are displayed in a formatted table:

```
========================================
  SCALABILITY BENCHMARK TEST RESULTS
========================================

Scale        | Operation       | Time (ms)    | Memory (MB)  | Labels/sec
--------------------------------------------------------------------------------
10           | Add             | 25.43        | 0.12         | 393
100          | Add             | 234.56       | 1.23         | 426
1,000        | Add             | 2,345.67     | 12.34        | 426
10,000       | Add             | 23,456.78    | 123.45       | 426
...
```

### Metrics Collected

For each operation at each scale:
- **Time (ms)**: Total time in milliseconds
- **Memory (MB)**: Memory used in megabytes
- **Labels/sec**: Throughput (labels per second)

### Performance Baselines

Expected performance characteristics:
- **Add/Update**: ~400-600 labels/sec (database-bound)
- **Fetch**: Sub-millisecond for <1,000 labels
- **Bulk Fetch**: Optimized with single query per file set
- **Delete**: Similar to add performance
- **Find by Label**: Index-optimized, <100ms for 1,000 files

### Use Cases

Run scalability benchmarks to:
- Identify performance regressions
- Validate optimizations
- Establish performance baselines
- Capacity planning
- Database tuning validation

## Code Quality Standards

All tests follow Nextcloud coding standards:
- Type declarations (`declare(strict_types=1)`)
- SPDX headers
- PSR-4 namespacing
- PHPDoc comments
- Extends `Test\TestCase` base class

## CI/CD Integration

The test suite is designed to run in:
- Local development environments
- GitHub Actions workflows
- Nextcloud CI pipelines

Exit codes:
- `0`: All tests passed
- Non-zero: Test failures or errors

## Frontend UI Testing

### Manual Testing

1. Build the frontend:
   ```bash
   npm run build
   ```

2. Start the development environment:
   ```bash
   podman-compose up -d
   ```

3. Enable the app:
   ```bash
   podman exec -u www-data nextcloud-files-labels-nextcloud-1 php occ app:enable files_labels
   ```

4. Access Nextcloud at http://localhost:8080
   - Username: `admin`
   - Password: `admin`

5. Test the Labels sidebar tab:
   - Navigate to the Files app
   - Upload or select an existing file
   - Click the file to open the sidebar
   - Look for the "Labels" tab (tag icon)
   - Click to open the Labels tab

### UI Test Cases

#### View Labels
- [ ] Empty state displays when no labels exist
- [ ] Existing labels display correctly (key-value pairs)
- [ ] Labels list is scrollable for files with many labels
- [ ] Loading state shows while fetching labels
- [ ] Error message displays if API request fails

#### Add Labels
- [ ] Form accepts valid key and value
- [ ] "Add label" button is disabled when form is empty
- [ ] "Add label" button is disabled while saving
- [ ] Success notification shows after adding label
- [ ] New label appears in the list immediately
- [ ] Form clears after successful addition
- [ ] Error shows if trying to add duplicate key
- [ ] Error shows if key exceeds 255 characters
- [ ] Error shows if value exceeds 4000 characters
- [ ] Validation prevents empty keys or values

#### Delete Labels
- [ ] Delete button appears for each label
- [ ] Success notification shows after deletion
- [ ] Label disappears from list immediately
- [ ] Error shows if deletion fails

#### Responsive Behavior
- [ ] Tab switches between files update labels correctly
- [ ] Labels refresh when switching between files
- [ ] Component handles file changes gracefully
- [ ] No memory leaks when switching between many files

#### API Integration
- [ ] GET request fetches labels correctly
- [ ] PUT request creates/updates labels correctly
- [ ] DELETE request removes labels correctly
- [ ] OCS API responses are handled properly
- [ ] Error responses show user-friendly messages

### Accessibility Testing

- [ ] Tab can be navigated using keyboard only
- [ ] Form inputs have proper labels
- [ ] Buttons have descriptive text or aria-labels
- [ ] Error messages are announced to screen readers
- [ ] Color contrast meets WCAG standards

### Browser Compatibility

Test in:
- [ ] Chrome/Chromium (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)

## Contributing

When adding new features:
1. Write tests first (TDD approach recommended)
2. Ensure new code has >80% coverage
3. Test both success and failure paths
4. Add edge case tests
5. Test UI changes in multiple browsers
6. Verify accessibility compliance
7. Update this document if adding new test categories
