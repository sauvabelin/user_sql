<?php

declare(strict_types=1);

namespace OCA\UserSQL\Command;

use OCA\UserSQL\Service\GroupSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncGroups extends Command {

	private GroupSyncService $syncService;

	public function __construct(GroupSyncService $syncService) {
		parent::__construct();
		$this->syncService = $syncService;
	}

	protected function configure(): void {
		$this
			->setName('usersql:sync-groups')
			->setDescription('Sync group memberships from external SQL database and dispatch events')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without dispatching events or updating snapshot')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Reset the snapshot table before syncing')
			->addOption('group', null, InputOption::VALUE_REQUIRED, 'Only sync a specific group');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$start = microtime(true);

		$options = [
			'dry_run' => $input->getOption('dry-run'),
			'reset' => $input->getOption('reset'),
			'group' => $input->getOption('group'),
		];

		$result = $this->syncService->sync($options);

		if ($input->getOption('verbose')) {
			foreach ($result['details'] as $detail) {
				$output->writeln("  {$detail}");
			}
		}

		$elapsed = round(microtime(true) - $start, 2);
		$prefix = $options['dry_run'] ? '[DRY-RUN] ' : '';
		$output->writeln("{$prefix}Sync completed in {$elapsed}s: {$result['added']} added, {$result['removed']} removed, {$result['errors']} errors");

		return $result['errors'] > 0 ? 1 : 0;
	}
}
