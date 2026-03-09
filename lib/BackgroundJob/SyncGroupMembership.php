<?php

declare(strict_types=1);

namespace OCA\UserSQL\BackgroundJob;

use OCA\UserSQL\Service\GroupSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class SyncGroupMembership extends TimedJob {

	private GroupSyncService $syncService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		GroupSyncService $syncService,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		$this->syncService = $syncService;
		$this->logger = $logger;
		$this->setInterval(300);
	}

	protected function run($argument): void {
		try {
			$result = $this->syncService->sync();
			$this->logger->info(
				"Group membership sync completed: {$result['added']} added, {$result['removed']} removed, {$result['errors']} errors"
			);
		} catch (\Exception $e) {
			$this->logger->error('Group membership sync failed: ' . $e->getMessage(), [
				'exception' => $e,
			]);
		}
	}
}
