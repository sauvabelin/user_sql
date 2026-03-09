<?php

declare(strict_types=1);

namespace OCA\UserSQL\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260309120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('usersql_group_sync')) {
			$table = $schema->createTable('usersql_group_sync');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('gid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('synced_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gid', 'uid'], 'usersql_gs_gid_uid');
			$table->addIndex(['gid'], 'usersql_gs_gid');
		}

		return $schema;
	}
}
