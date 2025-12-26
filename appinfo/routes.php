<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'ocs' => [
		// Bulk get labels for multiple files
		['name' => 'Labels#bulk', 'url' => '/api/v1/labels/bulk', 'verb' => 'POST'],
		// Get all labels for a file
		['name' => 'Labels#index', 'url' => '/api/v1/labels/{fileId}', 'verb' => 'GET'],
		// Set a single label
		['name' => 'Labels#set', 'url' => '/api/v1/labels/{fileId}/{key}', 'verb' => 'PUT'],
		// Delete a single label
		['name' => 'Labels#delete', 'url' => '/api/v1/labels/{fileId}/{key}', 'verb' => 'DELETE'],
		// Bulk set labels
		['name' => 'Labels#bulkSet', 'url' => '/api/v1/labels/{fileId}', 'verb' => 'PUT'],
	],
];
