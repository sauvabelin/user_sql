<?php

declare(strict_types=1);

namespace OCA\UserSQL\Service;

use OCA\UserSQL\Repository\GroupRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class GroupSyncService {

	private GroupRepository $groupRepository;
	private IDBConnection $db;
	private IEventDispatcher $dispatcher;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private LoggerInterface $logger;

	public function __construct(
		GroupRepository $groupRepository,
		IDBConnection $db,
		IEventDispatcher $dispatcher,
		IUserManager $userManager,
		IGroupManager $groupManager,
		LoggerInterface $logger
	) {
		$this->groupRepository = $groupRepository;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
	}

	/**
	 * @param array{dry_run?: bool, reset?: bool, group?: string} $options
	 * @return array{added: int, removed: int, errors: int, details: string[]}
	 */
	public function sync(array $options = []): array {
		$dryRun = $options['dry_run'] ?? false;
		$reset = $options['reset'] ?? false;
		$filterGroup = $options['group'] ?? null;

		$result = ['added' => 0, 'removed' => 0, 'errors' => 0, 'details' => []];

		// Reset snapshot if requested
		if ($reset && !$dryRun) {
			$this->db->getQueryBuilder()
				->delete('usersql_group_sync')
				->executeStatement();
			$result['details'][] = 'Snapshot table reset';
		}

		// Get all groups from external DB
		$groups = $this->groupRepository->findAllBySearchTerm('%');
		if ($groups === false) {
			$this->logger->error('Failed to fetch groups from external DB');
			$result['errors']++;
			return $result;
		}

		$currentGids = [];
		foreach ($groups as $group) {
			$currentGids[] = $group->gid;
		}

		if ($filterGroup !== null) {
			if (!in_array($filterGroup, $currentGids)) {
				$result['details'][] = "Group '{$filterGroup}' not found in external DB";
				$result['errors']++;
				return $result;
			}
			$currentGids = [$filterGroup];
		}

		// Load entire snapshot in one query
		$snapshot = $this->loadSnapshot();

		// Detect deleted groups (in snapshot but not in current)
		$snapshotGids = array_keys($snapshot);
		$deletedGids = array_diff($snapshotGids, $currentGids);
		if ($filterGroup !== null) {
			$deletedGids = []; // Don't process deleted groups when filtering
		}

		// Process deleted groups
		foreach ($deletedGids as $gid) {
			foreach ($snapshot[$gid] as $uid) {
				$this->processRemoval($gid, $uid, $dryRun, $result);
			}
		}

		// Process each current group
		foreach ($currentGids as $gid) {
			$currentMembers = $this->groupRepository->findAllUidsBySearchTerm($gid, '%');
			if ($currentMembers === false) {
				$this->logger->error("Failed to fetch members for group '{$gid}', skipping");
				$result['errors']++;
				$result['details'][] = "Error fetching members for group '{$gid}', skipped";
				continue;
			}

			$snapshotMembers = $snapshot[$gid] ?? [];
			$added = array_diff($currentMembers, $snapshotMembers);
			$removed = array_diff($snapshotMembers, $currentMembers);

			foreach ($added as $uid) {
				$this->processAddition($gid, $uid, $dryRun, $result);
			}

			foreach ($removed as $uid) {
				$this->processRemoval($gid, $uid, $dryRun, $result);
			}
		}

		return $result;
	}

	private function processAddition(string $gid, string $uid, bool $dryRun, array &$result): void {
		$user = $this->userManager->get($uid);
		$group = $this->groupManager->get($gid);

		if ($user === null || $group === null) {
			if ($user === null) {
				$result['details'][] = "Skip add: user '{$uid}' not found in Nextcloud";
			}
			if ($group === null) {
				$result['details'][] = "Skip add: group '{$gid}' not found in Nextcloud";
			}
			return;
		}

		$result['added']++;
		$result['details'][] = ($dryRun ? '[DRY-RUN] ' : '') . "Added '{$uid}' to '{$gid}'";

		if (!$dryRun) {
			$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
			$this->insertSnapshot($gid, $uid);
		}
	}

	private function processRemoval(string $gid, string $uid, bool $dryRun, array &$result): void {
		$user = $this->userManager->get($uid);
		$group = $this->groupManager->get($gid);

		$result['removed']++;
		$result['details'][] = ($dryRun ? '[DRY-RUN] ' : '') . "Removed '{$uid}' from '{$gid}'";

		if (!$dryRun) {
			if ($user !== null && $group !== null) {
				$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
			}
			$this->deleteSnapshot($gid, $uid);
		}
	}

	/**
	 * @return array<string, string[]>
	 */
	private function loadSnapshot(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid', 'uid')
			->from('usersql_group_sync');
		$cursor = $qb->executeQuery();

		$snapshot = [];
		while ($row = $cursor->fetch()) {
			$snapshot[$row['gid']][] = $row['uid'];
		}
		$cursor->closeCursor();

		return $snapshot;
	}

	private function insertSnapshot(string $gid, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('usersql_group_sync')
			->values([
				'gid' => $qb->createNamedParameter($gid),
				'uid' => $qb->createNamedParameter($uid),
				'synced_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
			])
			->executeStatement();
	}

	private function deleteSnapshot(string $gid, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('usersql_group_sync')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}
}
