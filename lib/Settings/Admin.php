<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Settings;

use OCA\FilesLabels\Service\LabelsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Admin settings for File Labels app.
 *
 * Allows administrators to configure app-wide settings like rate limits.
 */
class Admin implements ISettings {
	private const APP_ID = 'files_labels';

	public function __construct(
		private LabelsService $labelsService,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-settings', [
			'maxLabelsPerUser' => $this->labelsService->getMaxLabelsPerUser(),
		]);

		return new TemplateResponse(self::APP_ID, 'settings/admin');
	}

	public function getSection(): string {
		// Use the 'additional' section (appears under Administration > Additional settings)
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
