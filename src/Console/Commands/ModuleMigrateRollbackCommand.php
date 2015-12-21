<?php
namespace Caffeinated\Modules\Console\Commands;

use Caffeinated\Modules\Modules;
use Caffeinated\Modules\Traits\MigrationTrait;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class ModuleMigrateRollbackCommand extends Command
{
	use MigrationTrait, ConfirmableTrait;

	/**
	 * @var string $name The console command name.
	 */
	protected $name = 'module:migrate:rollback';

	/**
	 * @var string $description The console command description.
	 */
	protected $description = 'Rollback the last database migrations for a specific or all modules';

	/**
	 * @var \Caffeinated\Modules\Modules
	 */
	protected $module;

	/**
	 * @var \Illuminate\Database\Migrations\Migrator;
	 */
	protected $migrator;


	/**
	 * Create a new command instance.
	 *
	 * @param \Caffeinated\Modules\Modules $module
	 */
	public function __construct(Modules $module, Migrator $migrator)
	{
		parent::__construct();

		$this->module = $module;
		$this->migrator = $migrator;
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire()
	{
		if (! $this->confirmToProceed()) return null;

		$module = $this->argument('module');

		if ($module) {
			return $this->rollback($module);
		} else {
			foreach ($this->module->all() as $module) {
				$this->rollback($module['slug']);
			}
		}
	}

	/**
	 * Run the migration rollback for the specified module.
	 *
	 * @param  string $slug
	 * @return mixed
	 */
	protected function rollback($slug)
	{
		$moduleName = Str::studly($slug);

		$pretend = $this->input->getOption('pretend');

		$this->requireMigrations($moduleName);
		$migrationPath = $this->getMigrationPath($slug);
		$migrations = array_reverse($this->migrator->getMigrationFiles($migrationPath));

		$migrations = $this->getLatestMigrations($migrations);

		if (count($migrations) == 0) {
			$this->info('Nothing to rollback.');
			return;
		}

		foreach ($migrations as $migration) {
			$this->runDown($slug, $migration->migration, $pretend);
			$this->info('Migrate: [' . $slug . ']' . $migration->migration);
		}

	}

	/**
	 * Run "down" a migration instance.
	 *
	 * @param  string $slug
	 * @param  object $migration
	 * @param  bool   $pretend
	 * @return void
	 */
	protected function runDown($slug, $migration, $pretend)
	{
		$migrationPath = $this->getMigrationPath($slug);
		$file          = (string) $migrationPath.'/'.$migration.'.php';
		$classFile     = implode('_', array_slice(explode('_', basename($file, '.php')), 4));
		$class         = studly_case($classFile);
		$table         = $this->laravel['config']['database.migrations'];

		$instance = new $class;
		$instance->down();

		$this->laravel['db']->table($table)
			->where('migration', $migration)
			->delete();

	}

	/**
	 *
	 * find latest migrations
	 *
	 * @param $migrations
	 * @return array
	 */
	protected function getLatestMigrations($migrations){
		$table = $this->laravel['config']['database.migrations'];
		$migration_records = [];
		$batch = 0;
		foreach ($migrations as $migration) {
			$migration_record = $this->laravel['db']->table($table)
				->where('migration', $migration)->first();
			if ($migration_record) {
				if ($batch == 0) {
					$batch = $migration_record->batch;
					break;
				}
			}
		}
		foreach ($migrations as $migration) {
			$migration_record = $this->laravel['db']->table($table)
				->where('migration', $migration)->where('batch', $batch)->first();
			if ($migration_record) {
				array_unshift($migration_records, $migration_record);
			}
		}
		return $migration_records;
	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [['module', InputArgument::OPTIONAL, 'Module slug.']];
	}

	/**
	 * Get the console command options.
	 *
	 * @return array
	 */
	protected function getOptions()
	{
		return [
			['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to use.'],
			['force', null, InputOption::VALUE_NONE, 'Force the operation to run while in production.'],
			['pretend', null, InputOption::VALUE_NONE, 'Dump the SQL queries that would be run.']
		];
	}
}
