<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../lib/base.php';

// Fix for "Autoload path not allowed" errors
\OC::$loader->addValidRoot(OC::$SERVERROOT . '/tests');
\OC_App::loadApp('files_labels');

if (!class_exists('\PHPUnit\Framework\TestCase')) {
	require_once __DIR__ . '/../../../tests/lib/TestCase.php';
}
