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
use OCP\EventDispatcher\IEventDispatcher;

class GroupChangeController extends Controller {

	private $dispatcher;

	private $userManager;

	private $groupManager;

	public function __construct(string $appName, IRequest $request, IEventDispatcher $eventDispatcher, IUserManager $userManager, IGroupManager $groupManager) {
		$this->dispatcher = $eventDispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		parent::__construct($appName, $request);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function syncGroup(string $username, string $groupname, string $operation) {

		$ud = base64_decode($username);
		$gd = base64_decode($groupname);
		$user = $this->userManager->get($ud);
		$group = $this->groupManager->get($gd);

		if ($user && $group) {

			$done = false;
			if ($operation === 'join') {
				$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
				$done = true;
			} else if ($operation === 'leave') {
				$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
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
}
