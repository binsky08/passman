<?php
/**
 * Nextcloud - passman
 *
 * @copyright binsky (timo@binsky.org)
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\PassmanNext\Command;

use OCA\PassmanNext\Exception\NonInteractiveShellException;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PassmanLegacyMigrateCommand extends AbstractInteractiveCommand
{
	const OLD_TABLE_PREFIX = 'passman_';
	const NEW_TABLE_PREFIX = 'passman_next_';
	const MIGRATE_TABLE_NAMES = [
		'vaults',
		'credentials',
		'files',
		'revisions',
		'sharing_acl',
		'share_request',
		'delete_vault_request'
	];
	const CHECK_ICON = "✅";
	const PROBLEM_ICON = "❗";
	const MINIMUM_PASSMAN_LEGACY_VERSION = "2.4.0";

	/**
	 * PassmanLegacyMigrateCommand constructor.
	 *
	 * @param IDBConnection $db
	 * @param IAppConfig $config
	 * @param string|null $name
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $config,
		?string $name = null
	) {
		parent::__construct($name);
	}


	protected function configure(): void {
		$this->setName('passman-next:migrate-legacy')
			->setDescription('Migrates all data from the Passman (legacy) app into the Passman Next database tables');
		parent::configure();
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 * @throws NonInteractiveShellException|Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		parent::execute($input, $output);

		$installedPassmanVersion = $this->config->getValueString('passman', 'installed_version');
		$passmanLegacyVersionSupported = false;
		if (version_compare($installedPassmanVersion, self::MINIMUM_PASSMAN_LEGACY_VERSION, '>=')) {
			$passmanLegacyVersionSupported = true;
		}
		$output->writeln(sprintf(
			"Found legacy Passman %s installation %s",
			$installedPassmanVersion,
			$passmanLegacyVersionSupported ? self::CHECK_ICON : self::PROBLEM_ICON
		));
		if (!$passmanLegacyVersionSupported) {
			$output->writeln('Supported Passman versions: >= ' . self::MINIMUM_PASSMAN_LEGACY_VERSION);
			return 1;
		}

		$oldTableEntriesCountMap = [];
		$newDataTableEntries = 0;

		$output->writeln("\nData to migrate:");
		foreach (self::MIGRATE_TABLE_NAMES as $mainTableName) {
			$oldTableEntriesCount = $this->countAll(self::OLD_TABLE_PREFIX . $mainTableName);
			$newTableEntriesCount = $this->countAll(self::NEW_TABLE_PREFIX . $mainTableName);

			$output->writeln(
				sprintf(
					"- %s: \n  - Legacy: %d \n  - Passman Next: %d %s",
					$mainTableName,
					$oldTableEntriesCount,
					$newTableEntriesCount,
					$newTableEntriesCount === 0 ? self::CHECK_ICON : self::PROBLEM_ICON
				)
			);

			$newDataTableEntries += $newTableEntriesCount;
			$oldTableEntriesCountMap[$mainTableName] = $oldTableEntriesCount;
		}

		if ($newDataTableEntries !== 0) {
			$output->writeln(
				"\n❗❗❗ You have already data in the Passman Next database tables. These will be deleted if you proceed! ❗❗❗\n"
			);
		}

		if ($this->confirmMigration($input, $output)) {
			try {
				$this->db->beginTransaction();

				foreach (self::MIGRATE_TABLE_NAMES as $mainTableName) {
					$this->migrateTableData(
						self::OLD_TABLE_PREFIX . $mainTableName,
						self::NEW_TABLE_PREFIX . $mainTableName
					);

					$newCount = $this->countAll(self::NEW_TABLE_PREFIX . $mainTableName);
					if ($oldTableEntriesCountMap[$mainTableName] !== $newCount) {
						// new table entries count does not match the original ones
						$output->writeln(
							sprintf(
								'New table entries count in "%s" (%d) does not match the original ones (%d)',
								self::NEW_TABLE_PREFIX . $mainTableName,
								$newCount,
								$oldTableEntriesCountMap[$mainTableName]
							)
						);
					}
				}

				$this->db->commit();
			} catch (\Exception $exception) {
				$output->writeln($exception->getMessage());
				$output->writeln("\nRoll back transaction ...");
				$this->db->rollBack();
				$output->writeln("No data has been changed.");
				return 1;
			}

			$output->writeln('Done');
			return 0;
		}

		return 1;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return bool
	 */
	protected function confirmMigration(InputInterface $input, OutputInterface $output): bool {
		return $this->requestConfirmation(
			$input,
			$output,
			'Please confirm the data migration. It won\'t delete anything in the sources.'
		);
	}

	/**
	 * KEEP THIS METHOD PRIVATE!!!
	 *
	 * @param string $table
	 * @return array
	 * @throws Exception
	 */
	private function fetchAll(string $table): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')
			->from($table)
			->executeQuery();
		return $result->fetchAll();
	}

	private function countAll(string $table, string $uniqueColumn = 'id'): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($uniqueColumn)
			->from($table)
			->executeQuery();
		return $result->rowCount();
	}

	private function migrateTableData(string $legacyTableName, string $newTableName) {
		// ensure the destination table is cleared
		$deleteQueryBuilder = $this->db->getQueryBuilder();
		$deleteQueryBuilder->delete($newTableName)->executeStatement();

		$data = $this->fetchAll($legacyTableName);
		foreach ($data as $datum) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert($newTableName);

			foreach ($datum as $key => $value) {
				if (is_null($value)) {
					$value = 'NULL';
				} elseif (!is_numeric($value)) {
					$value = "'{$value}'";
				}

				$qb->setValue($key, $value);
			}

			$qb->executeStatement();
		}
	}
}
