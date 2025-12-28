<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for admin settings.
 */
class AdminController extends Controller {
	private const APP_ID = 'files_labels';
	private const CONFIG_KEY_MAX_LABELS = 'max_labels_per_user';
	private const DEFAULT_MAX_LABELS = 10000;
	private const MIN_MAX_LABELS = 100;
	private const MAX_MAX_LABELS = 1000000;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get current admin settings
	 */
	#[AuthorizedAdminSetting(settings: \OCA\FilesLabels\Settings\Admin::class)]
	public function getSettings(): JSONResponse {
		return new JSONResponse([
			'maxLabelsPerUser' => (int)$this->config->getAppValue(
				self::APP_ID,
				self::CONFIG_KEY_MAX_LABELS,
				(string)self::DEFAULT_MAX_LABELS
			),
		]);
	}

	/**
	 * Update max labels per user setting
	 */
	#[AuthorizedAdminSetting(settings: \OCA\FilesLabels\Settings\Admin::class)]
	public function setMaxLabelsPerUser(): JSONResponse {
		$value = $this->request->getParam('value');

		if (!is_numeric($value)) {
			return new JSONResponse(
				['error' => 'Value must be a number'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$intValue = (int)$value;

		if ($intValue < self::MIN_MAX_LABELS || $intValue > self::MAX_MAX_LABELS) {
			return new JSONResponse(
				['error' => "Value must be between " . self::MIN_MAX_LABELS . " and " . self::MAX_MAX_LABELS],
				Http::STATUS_BAD_REQUEST
			);
		}

		$this->config->setAppValue(self::APP_ID, self::CONFIG_KEY_MAX_LABELS, (string)$intValue);

		$this->logger->info('Admin updated max labels per user', [
			'app' => self::APP_ID,
			'maxLabelsPerUser' => $intValue,
		]);

		return new JSONResponse([
			'maxLabelsPerUser' => $intValue,
		]);
	}
}
