<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20241222000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('file_labels')) {
			$table = $schema->createTable('file_labels');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('label_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('label_value', Types::TEXT, [
				'notnull' => true,
			]);

			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);

			// Unique constraint: one label key per file per user
			$table->addUniqueIndex(['file_id', 'user_id', 'label_key'], 'file_labels_file_user_key');

			// Index for querying by user and label (e.g., find all sensitive files)
			$table->addIndex(['user_id', 'label_key'], 'file_labels_user_key');

			// Index for bulk fetch (get all labels for files in a directory)
			$table->addIndex(['file_id', 'user_id'], 'file_labels_file_user');
		}

		return $schema;
	}
}
