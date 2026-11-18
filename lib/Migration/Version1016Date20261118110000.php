<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1016Date20261118110000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('docuseal_requests')) {
			return $schema;
		}

		$table = $schema->getTable('docuseal_requests');
		if (!$table->hasColumn('delivery_token')) {
			$table->addColumn('delivery_token', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$table->hasColumn('delivery_claimed_at')) {
			$table->addColumn('delivery_claimed_at', Types::BIGINT, [
				'notnull' => false,
			]);
		}
		return $schema;
	}
}
