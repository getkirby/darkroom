<?php

namespace Darkroom\Playground;

use Darkroom\Darkroom;
use Darkroom\Driver;
use Darkroom\Options;
use Darkroom\Plan;
use Darkroom\Source;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class Playground
{
	public readonly Cache $cache;
	public readonly array $cases;
	public readonly array $sources;

	/**
	 * @var array<string, Darkroom>
	 */
	protected array $darkrooms = [];
	protected array|null $drivers = null;

	/**
	 * @var array<string, Source>
	 */
	protected array $files = [];

	public function __construct(
		public readonly string $root
	) {
		$code = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(dirname($root) . '/src', FilesystemIterator::SKIP_DOTS)
		);

		$this->cache = new Cache(
			root:  $root . '/cache',
			files: [...$code, ...glob($root . '/*.php') ?: [], ...glob($root . '/sources/*') ?: []]
		);

		$this->cases   = require $root . '/cases.php';
		$this->sources = require $root . '/sources.php';

		$this->cache->prune();
	}

	public function checks(Filter $filter): Checks
	{
		return new Checks($this, $filter);
	}

	protected function darkroom(string ...$drivers): Darkroom
	{
		return $this->darkrooms[implode(',', $drivers)] ??= new Darkroom($drivers);
	}

	public function drivers(): array
	{
		return $this->drivers ??= $this->cache->remember('drivers', $this->probe(...));
	}

	public function facts(string $name): array
	{
		return Cell::facts($this->root . '/sources/' . $name);
	}

	protected function make(string $key, Source $source, array $options): array
	{
		$dir   = $this->cache->dir . '/' . $key;
		$url   = '/cache/' . $this->cache->fingerprint . '/' . $key;
		$cells = [];

		foreach ($this->drivers() as $driver => $info) {
			$cell = new Cell(
				darkroom: $this->darkroom($driver),
				info:     $info,
				source:   $source,
				options:  $options
			);

			$cells[$driver] = $cell->make($dir . '/' . $driver, $url . '/' . $driver);
		}

		return Delta::add($cells, $dir);
	}

	/**
	 * The size a page knows before any driver runs
	 *
	 * @return array{int, int}
	 */
	protected function plan(Source $source, array $options): array
	{
		$options = Options::from($options);
		$plan    = Plan::for($source, $options);
		$plan    = Cell::plan($plan);

		return $plan['size'];
	}

	protected function probe(): array
	{
		$drivers = [];

		foreach (glob(dirname($this->root) . '/src/Drivers/*.php') ?: [] as $file) {
			$class = 'Darkroom\\Drivers\\' . basename($file, '.php');
			$name  = strtolower(basename($file, '.php'));

			// the folder also holds helpers the drivers share
			if (is_subclass_of($class, Driver::class) === false) {
				continue;
			}

			try {
				$darkroom = $this->darkroom($name);
				$info     = $darkroom->info()[0];
			} catch (Throwable $exception) {
				$info = [
					'available' => false,
					'error'     => $exception::class . ': ' . $exception->getMessage(),
				];
			}

			$drivers[$name] = $info;
		}

		return $drivers;
	}

	/**
	 * The cases the filter selects with their planned size
	 * and the shown drivers' cells
	 */
	public function rows(Filter $filter): array
	{
		$issues = $filter->has('issues');
		$shown  = array_flip($this->shown($filter));
		$rows   = [];

		foreach ($this->select($filter) as [$name, $group, $case]) {
			$key     = $name . '/' . $case;
			$source  = $this->source($name);
			$options = $this->cases[$group][$case];
			$make    = fn (): array => $this->make($key, $source, $options);
			$cells   = $this->cache->remember($key, $make);
			$cells   = array_intersect_key($cells, $shown);

			if ($issues === true && in_array('issues', array_column($cells, 'state'), true) === false) {
				continue;
			}

			$rows[] = [
				'source'  => $name,
				'group'   => $group,
				'options' => $options,
				'size'    => $this->plan($source, $options),
				'cells'   => $cells,
			];
		}

		return $rows;
	}

	/**
	 * Source, group and case of each row the filter selects
	 *
	 * @return list<array{string, string, string}>
	 */
	protected function select(Filter $filter): array
	{
		$rows = [];

		foreach ($filter->select('source', array_keys($this->sources)) as $source) {
			foreach ($filter->select('group', $this->sources[$source]['groups']) as $group) {
				foreach ($filter->select('case', array_keys($this->cases[$group])) as $case) {
					$rows[] = [$source, $group, $case];
				}
			}
		}

		return $rows;
	}

	public function shown(Filter $filter): array
	{
		return $filter->select('driver', array_keys($this->drivers()));
	}

	protected function source(string $name): Source
	{
		return $this->files[$name] ??= new Source($this->root . '/sources/' . $name);
	}
}
