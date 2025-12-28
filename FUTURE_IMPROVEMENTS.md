# Future Improvements

This document tracks potential enhancements and features for the File Labels app.

## Label Search API

We need a way to search for files that match a specific label or set of labels. Use cases:

1. Find all files with label "sensitive=true"
2. Find all files with label "status" (any value)
3. Find all files matching multiple labels (AND logic): "project=alpha" AND "status=approved"
4. Find all files matching any of multiple labels (OR logic)

### Technical Considerations

- Need efficient indexes for label_key and label_value queries
- Consider the label_value_hash approach for exact value matching
- Pagination required for large result sets
- Permission filtering must be applied to results

### API Design Ideas

- `GET /apps/files_labels/api/v1/search?labels=key1:value1,key2:value2`
- Support for wildcards on values
- Return file IDs that can be resolved to paths

## Related Features

These features are dependent on or complementary to label search:

- **Label Statistics**: Count files by label across user's library
- **Label Suggestions**: Auto-suggest labels based on file properties or usage patterns
- **Label Presets**: Save and reuse common label combinations
- **Label Inheritance**: Apply labels to all files in a directory
- **Advanced Filtering**: Filter file listings by labels in the UI
