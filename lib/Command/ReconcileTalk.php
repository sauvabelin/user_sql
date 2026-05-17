<?php

declare(strict_types=1);

namespace OCA\UserSQL\Command;

use OCA\UserSQL\Service\TalkReconcileService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileTalk extends Command {

	private TalkReconcileService $reconcileService;

	public function __construct(TalkReconcileService $reconcileService) {
		parent::__construct();
		$this->reconcileService = $reconcileService;
	}

	protected function configure(): void {
		$this
			->setName('usersql:reconcile-talk')
			->setDescription('Remove Talk attendees whose room is linked to one or more groups but who are no longer a member of any of them. Designed to be run rarely, when historical drift is suspected. Not registered as a background job on purpose.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the removals that would happen without dispatching events');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$start = microtime(true);
		$dryRun = (bool)$input->getOption('dry-run');

		$result = $this->reconcileService->reconcile($dryRun);

		if ($result['unavailable']) {
			$output->writeln('<comment>' . $result['details'][0] . '</comment>');
			return 0;
		}

		if ($input->getOption('verbose')) {
			foreach ($result['details'] as $detail) {
				$output->writeln("  {$detail}");
			}
		}

		$elapsed = round(microtime(true) - $start, 2);
		$prefix = $dryRun ? '[DRY-RUN] ' : '';
		$output->writeln(
			"{$prefix}Talk reconcile completed in {$elapsed}s: "
			. "checked {$result['checked']} attendees, "
			. "{$result['removed']} removed, "
			. "{$result['skipped']} skipped, "
			. "{$result['errors']} errors"
		);

		return $result['errors'] > 0 ? 1 : 0;
	}
}
