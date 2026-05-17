<?php

declare(strict_types=1);

namespace OCA\UserSQL\Service;

use OC\Hooks\PublicEmitter;
use OCA\UserSQL\Backend\GroupBackend;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reconcile Talk room membership against the current state of the external
 * SQL database. Removes per-user Talk attendees whose room is linked to one
 * or more groups but who are not (any more) a member of any of those groups.
 *
 * Why this lives outside the periodic sync:
 *
 * The periodic sync is diff-based — it can only emit UserRemovedEvent for
 * (gid, uid) pairs the snapshot remembers. If a user was added to a group,
 * Talk materialised them as a per-user attendee, and the *remove* event was
 * subsequently lost AND the snapshot didn't record either step, the periodic
 * sync has no way to discover the drift: snapshot agrees with current state,
 * diff is empty.
 *
 * Reading Talk's tables to find these orphan attendees is the only way to
 * recover from such historical drift. The trade-off is that this service is
 * coupled to Talk's schema. Intentional: it's manually invoked, the rest of
 * the app is Talk-agnostic, and Talk's schema is stable enough that a
 * column-rename would just produce a clear failure here rather than silent
 * corruption.
 */
class TalkReconcileService {

	private IDBConnection $db;
	private IEventDispatcher $dispatcher;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private GroupBackend $groupBackend;
	private LoggerInterface $logger;

	public function __construct(
		IDBConnection $db,
		IEventDispatcher $dispatcher,
		IGroupManager $groupManager,
		IUserManager $userManager,
		GroupBackend $groupBackend,
		LoggerInterface $logger
	) {
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->groupBackend = $groupBackend;
		$this->logger = $logger;
	}

	/**
	 * @return array{checked:int,removed:int,skipped:int,errors:int,unavailable:bool,details:string[]}
	 */
	public function reconcile(bool $dryRun = false): array {
		$result = ['checked' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => 0, 'unavailable' => false, 'details' => []];

		if (!$this->db->tableExists('talk_attendees')) {
			$result['unavailable'] = true;
			$result['details'][] = 'Talk (spreed) is not installed or its tables are absent – nothing to reconcile.';
			return $result;
		}

		// Talk's per-room participant types (mirrored from
		// OCA\Talk\Participant): OWNER=1, MODERATOR=2, USER=3, GUEST=4,
		// USER_SELF_JOINED=5. We only touch USER (3) – owners/moderators and
		// self-joined users opted into the conversation independently of any
		// group link, so removing them via reconcile would surprise people.
		$candidates = $this->findGroupLinkedUserAttendees();
		$result['checked'] = count($candidates);

		// Group the (room, user, linked-gid) rows by (room, user) so we can
		// answer "is this user in ANY of the room's linked groups" with one
		// pass through their current memberships.
		$byRoomUser = [];
		foreach ($candidates as $row) {
			$key = $row['room_id'] . '|' . $row['uid'];
			$byRoomUser[$key]['room_id'] = (int)$row['room_id'];
			$byRoomUser[$key]['uid'] = $row['uid'];
			$byRoomUser[$key]['linked_gids'][] = $row['gid'];
		}

		foreach ($byRoomUser as $entry) {
			$uid = $entry['uid'];
			$linkedGids = $entry['linked_gids'];
			$roomId = $entry['room_id'];

			$user = $this->userManager->get($uid);
			if ($user === null) {
				$result['skipped']++;
				$result['details'][] = "Skip room {$roomId}: user '{$uid}' does not exist in NC";
				continue;
			}

			$stillLinked = false;
			foreach ($linkedGids as $gid) {
				if ($this->groupManager->isInGroup($uid, $gid)) {
					$stillLinked = true;
					break;
				}
			}

			if ($stillLinked) {
				$result['skipped']++;
				continue;
			}

			// User is in Talk room $roomId via at least one group attendee
			// but is no longer a member of ANY of those groups in the
			// external DB. Fire UserRemovedEvent through any of the linked
			// groups – Talk's GroupMembershipListener re-checks all rooms
			// for the actor and applies the same "filter rooms with other
			// links" logic, so the choice of group does not change the
			// outcome.
			$gid = $linkedGids[0];
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				$result['skipped']++;
				$result['details'][] = "Skip room {$roomId}: linked group '{$gid}' not resolvable";
				continue;
			}

			$result['details'][] = ($dryRun ? '[DRY-RUN] ' : '')
				. "Remove '{$uid}' from Talk room {$roomId} (no longer in "
				. implode(', ', $linkedGids) . ')';

			if ($dryRun) {
				$result['removed']++;
				continue;
			}

			try {
				$this->groupBackend->invalidateUserGroupCache($uid, $gid);
				if ($this->groupManager instanceof PublicEmitter) {
					$this->groupManager->emit('\OC\Group', 'preRemoveUser', [$group, $user]);
					$this->groupManager->emit('\OC\Group', 'postRemoveUser', [$group, $user]);
				}
				$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
				$result['removed']++;
			} catch (\Throwable $e) {
				$result['errors']++;
				$result['details'][] = "Error reconciling '{$uid}' in room {$roomId}: " . $e->getMessage();
				$this->logger->error(
					"Talk reconcile failed for user '{$uid}' in room {$roomId}",
					['exception' => $e]
				);
			}
		}

		return $result;
	}

	/**
	 * @return list<array{room_id:int,uid:string,gid:string}>
	 */
	private function findGroupLinkedUserAttendees(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.room_id', 'u.actor_id', 'g.actor_id')
			->from('talk_attendees', 'u')
			->innerJoin('u', 'talk_attendees', 'g', $qb->expr()->andX(
				$qb->expr()->eq('g.room_id', 'u.room_id'),
				$qb->expr()->eq('g.actor_type', $qb->createNamedParameter('groups'))
			))
			->where($qb->expr()->eq('u.actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('u.participant_type', $qb->createNamedParameter(3, \PDO::PARAM_INT)));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($r = $cursor->fetch(\PDO::FETCH_NUM)) {
			$rows[] = ['room_id' => (int)$r[0], 'uid' => (string)$r[1], 'gid' => (string)$r[2]];
		}
		$cursor->closeCursor();
		return $rows;
	}
}
