<?php

declare(strict_types=1);

namespace OCA\UserSQL\Service;

use OC\Hooks\PublicEmitter;
use OCA\UserSQL\Backend\GroupBackend;
use OCA\UserSQL\Repository\GroupRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class GroupSyncService {

	private GroupRepository $groupRepository;
	private GroupBackend $groupBackend;
	private IDBConnection $db;
	private IEventDispatcher $dispatcher;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private LoggerInterface $logger;

	public function __construct(
		GroupRepository $groupRepository,
		GroupBackend $groupBackend,
		IDBConnection $db,
		IEventDispatcher $dispatcher,
		IUserManager $userManager,
		IGroupManager $groupManager,
		LoggerInterface $logger
	) {
		$this->groupRepository = $groupRepository;
		$this->groupBackend = $groupBackend;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
	}

	/**
	 * @param array{dry_run?: bool, reset?: bool, group?: string} $options
	 * @return array{added: int, removed: int, errors: int, skipped: int, details: string[]}
	 */
	public function sync(array $options = []): array {
		$dryRun = $options['dry_run'] ?? false;
		$reset = $options['reset'] ?? false;
		$filterGroup = $options['group'] ?? null;

		$result = ['added' => 0, 'removed' => 0, 'errors' => 0, 'skipped' => 0, 'details' => []];

		if ($reset && !$dryRun) {
			$this->db->getQueryBuilder()
				->delete('usersql_group_sync')
				->executeStatement();
			$result['details'][] = 'Snapshot table reset';
		}

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
			if (!in_array($filterGroup, $currentGids, true)) {
				$result['details'][] = "Group '{$filterGroup}' not found in external DB";
				$result['errors']++;
				return $result;
			}
			$currentGids = [$filterGroup];
		}

		$snapshot = $this->loadSnapshot();

		$snapshotGids = array_keys($snapshot);
		$deletedGids = $filterGroup !== null ? [] : array_diff($snapshotGids, $currentGids);

		foreach ($deletedGids as $gid) {
			foreach ($snapshot[$gid] as $uid) {
				$this->processRemoval($gid, $uid, $dryRun, $result);
			}
		}

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
		// Invalidate our own membership cache before resolving the user/group
		// so that getUserGroups()/inGroup()/usersInGroup() reflect the current
		// external state rather than a stale cache entry.
		if (!$dryRun) {
			$this->groupBackend->invalidateUserGroupCache($uid, $gid);
		}

		$user = $this->userManager->get($uid);
		$group = $this->groupManager->get($gid);

		if ($user === null || $group === null) {
			if ($user === null) {
				$result['details'][] = "Skip add: user '{$uid}' not found in Nextcloud";
			}
			if ($group === null) {
				$result['details'][] = "Skip add: group '{$gid}' not found in Nextcloud";
			}
			$result['skipped']++;
			return;
		}

		$result['added']++;
		$result['details'][] = ($dryRun ? '[DRY-RUN] ' : '') . "Added '{$uid}' to '{$gid}'";

		if (!$dryRun) {
			try {
				// Order matches OC\Group\Group::addUser(): pre-hook, mutation
				// (already done in the external DB), post-hook, then typed
				// event. OC\Group\Manager listens to 'postAddUser' to clear
				// its cachedUserGroups, so the cache MUST be flushed before
				// the typed event fires – otherwise listeners such as Talk
				// query getUserGroupIds() and get a stale answer.
				$this->emitLegacyHook('preAddUser', $group, $user);
				$this->emitLegacyHook('postAddUser', $group, $user);
				$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
				$this->insertSnapshot($gid, $uid);
			} catch (\Throwable $e) {
				// A listener threw (Talk, mail, custom apps, ...). Don't let one
				// faulty listener brick the rest of the sync run – count it as an
				// error and continue. The snapshot is intentionally not written so
				// the next run retries.
				$result['errors']++;
				$result['added']--;
				$result['details'][] = "Error adding '{$uid}' to '{$gid}': " . $e->getMessage();
				$this->logger->error(
					"Listener failed while adding '{$uid}' to '{$gid}'",
					['exception' => $e]
				);
			}
		}
	}

	private function processRemoval(string $gid, string $uid, bool $dryRun, array &$result): void {
		// Critical: invalidate our caches BEFORE Talk (and other listeners)
		// react to the typed event. Talk calls IGroupManager::getUserGroupIds()
		// from its UserRemovedEvent listener to decide whether the user is
		// still a member of the group via another link; if our cache still
		// reports the user as a member, Talk will not remove them from the
		// linked rooms.
		if (!$dryRun) {
			$this->groupBackend->invalidateUserGroupCache($uid, $gid);
		}

		$user = $this->userManager->get($uid);
		$group = $this->groupManager->get($gid);

		if ($user === null || $group === null) {
			// Nothing to notify – the user or group no longer exists in
			// Nextcloud. Drop the snapshot entry so we do not retry forever.
			if (!$dryRun) {
				$this->deleteSnapshot($gid, $uid);
			}
			$result['skipped']++;
			$result['details'][] = "Skip remove: user '{$uid}' or group '{$gid}' not in Nextcloud";
			return;
		}

		$result['removed']++;
		$result['details'][] = ($dryRun ? '[DRY-RUN] ' : '') . "Removed '{$uid}' from '{$gid}'";

		if (!$dryRun) {
			try {
				// Order matches OC\Group\Group::removeUser(): pre-hook,
				// mutation (already done in the external DB), post-hook,
				// then typed event. OC\Group\Manager listens to
				// 'postRemoveUser' to clear cachedUserGroups, so the cache
				// MUST be flushed before the typed event fires – otherwise
				// Talk's GroupMembershipListener queries getUserGroupIds()
				// from filterRoomsWithOtherGroupMemberships and reads the
				// stale list, deciding the user is "still linked" and
				// keeping them in the conversation.
				$this->emitLegacyHook('preRemoveUser', $group, $user);
				$this->emitLegacyHook('postRemoveUser', $group, $user);
				$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
				$this->deleteSnapshot($gid, $uid);
			} catch (\Throwable $e) {
				$result['errors']++;
				$result['removed']--;
				$result['details'][] = "Error removing '{$uid}' from '{$gid}': " . $e->getMessage();
				$this->logger->error(
					"Listener failed while removing '{$uid}' from '{$gid}'",
					['exception' => $e]
				);
			}
		}
	}

	private function emitLegacyHook(string $method, IGroup $group, IUser $user): void {
		if ($this->groupManager instanceof PublicEmitter) {
			$this->groupManager->emit('\OC\Group', $method, [$group, $user]);
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
