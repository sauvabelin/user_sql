<?php

declare(strict_types=1);

namespace OCA\UserSQL\Controller;

use OC\Hooks\PublicEmitter;
use OCA\UserSQL\Backend\GroupBackend;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\EventDispatcher\IEventDispatcher;

class GroupChangeController extends Controller {

	private IEventDispatcher $dispatcher;

	private IUserManager $userManager;

	private IGroupManager $groupManager;

	private GroupBackend $groupBackend;

	public function __construct(string $appName, IRequest $request, IEventDispatcher $eventDispatcher, IUserManager $userManager, IGroupManager $groupManager, GroupBackend $groupBackend) {
		$this->dispatcher = $eventDispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->groupBackend = $groupBackend;
		parent::__construct($appName, $request);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function syncGroup(string $username, string $groupname, string $operation) {

		$ud = base64_decode($username);
		$gd = base64_decode($groupname);

		// Drop our membership cache before resolving the IUser/IGroup so that
		// listeners (e.g. Nextcloud Talk) querying memberships during the
		// dispatched event read the freshly synced state.
		$this->groupBackend->invalidateUserGroupCache($ud, $gd);

		$user = $this->userManager->get($ud);
		$group = $this->groupManager->get($gd);

		if ($user && $group) {

			$done = false;
			if ($operation === 'join') {
				$this->emitLegacyHook('preAddUser', $group, $user);
				$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
				$this->emitLegacyHook('postAddUser', $group, $user);
				$done = true;
			} else if ($operation === 'leave') {
				$this->emitLegacyHook('preRemoveUser', $group, $user);
				$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
				$this->emitLegacyHook('postRemoveUser', $group, $user);
				$done = true;
			}

			return new JSONResponse(
				[
					'operation' => $operation,
					'userid' => $ud,
					'groupid' => $gd,
					'ok' => $done,
					'error' => [],
				]
			);
		}

		return new JSONResponse(
			[
				'operation' => $operation,
				'userid' => $ud,
				'groupid' => $gd,
				'ok' => false,
				'error' => [
					'user_found' => $user !== null,
					'group_found' => $group !== null,
				]
			]
		);
	}

	private function emitLegacyHook(string $method, IGroup $group, IUser $user): void {
		// OC\Group\Manager listens to these legacy hooks and clears its
		// internal cachedUserGroups. The typed events alone do NOT clear it,
		// which causes IGroupManager::getUserGroupIds() to return stale data
		// to listeners reacting to UserAddedEvent / UserRemovedEvent.
		if ($this->groupManager instanceof PublicEmitter) {
			$this->groupManager->emit('\OC\Group', $method, [$group, $user]);
		}
	}
}
