<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20251227000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('file_labels')) {
			$table = $schema->getTable('file_labels');

			// Add index on file_id for faster file deletion operations
			// DELETE FROM file_labels WHERE file_id = ? benefits from this
			if (!$table->hasIndex('file_labels_file_id')) {
				$table->addIndex(['file_id'], 'file_labels_file_id');
				return $schema;
			}
		}

		return null;
	}
}
