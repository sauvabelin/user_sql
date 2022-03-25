<?php

declare(strict_types=1);

namespace OCA\UserSQL\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

class GroupChangeController extends Controller {

	private $dispatcher;

	private $userManager;

	private $groupManager;

	public function __construct(string $appName, IRequest $request, EventDispatcher $eventDispatcher, IUserManager $userManager, IGroupManager $groupManager) {
		$this->dispatcher = $eventDispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		parent::__construct($appName, $request);
	}

	public function syncGroup(string $username, string $groupname, string $operation) {

		$user = $this->userManager->get($username);
		$group = $this->groupManager->get($groupname);

		if ($user && $group) {

			$done = false;
			if ($operation === 'join' && $group->inGroup($user)) {
				$this->dispatcher->dispatch(new UserAddedEvent($group, $user));
				$done = true;
			} else if ($operation === 'leave' && !$group->inGroup($user)) {
				$this->dispatcher->dispatch(new UserRemovedEvent($group, $user));
				$done = true;
			}

			return new JSONResponse(
				[
					'operation' => $operation,
					'userid' => $username,
					'groupid' => $groupname,
					'ok' => $done,
					'error' => [],
				]
			);
		}

		return new JSONResponse(
			[
				'operation' => $operation,
				'userid' => $username,
				'groupid' => $groupname,
				'ok' => false,
				'error' => [
					'user_found' => $user !== null,
					'group_found' => $group !== null,
				]
			]
		);
	}
}
