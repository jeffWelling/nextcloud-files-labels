<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Db;

use OCP\AppFramework\Db\Entity;
use DateTime;

/**
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getLabelKey()
 * @method void setLabelKey(string $key)
 * @method string getLabelValue()
 * @method void setLabelValue(string $value)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Label extends Entity {
	/** @var int */
	protected $fileId;
	/** @var string */
	protected $userId;
	/** @var string */
	protected $labelKey;
	/** @var string */
	protected $labelValue;
	/** @var DateTime|null */
	protected $createdAt;
	/** @var DateTime|null */
	protected $updatedAt;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function toArray(): array {
		return [
			'id' => $this->getId(),
			'fileId' => $this->getFileId(),
			'key' => $this->getLabelKey(),
			'value' => $this->getLabelValue(),
			'createdAt' => $this->getCreatedAt()?->format('c'),
			'updatedAt' => $this->getUpdatedAt()?->format('c'),
		];
	}
}
