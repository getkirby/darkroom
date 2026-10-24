<?php

namespace Darkroom\Playground;

final class View
{
	public function __construct(
		protected readonly Playground $playground,
		protected readonly Filter $filter,
	) {
	}

	protected static function bytes(int $bytes): string
	{
		if ($bytes < 1000) {
			return $bytes . ' B';
		}

		return round($bytes / 1000, 1) . ' KB';
	}

	protected function cell(array $cell): array
	{
		$delta       = $cell['delta'] ?? null;
		$output      = $cell['output'] ?? null;
		$differences = [];

		foreach ($cell['differences'] ?? [] as $driver => $value) {
			$differences[] = $driver . ' ' . static::percent($value);
		}

		$facts = $output === null ? null : static::facts($output) . ' · ' . $cell['ms'] . ' ms';
		$title = 'Root mean square error to ' . implode(', ', $differences);

		return [
			'state'  => $cell['state'],
			'url'    => $cell['url'] ?? null,
			'facts'  => $facts,
			'delta'  => $delta === null ? null : 'Δ ' . static::percent($delta),
			'level'  => $delta === null ? null : static::level($delta),
			'title'  => $title,
			'issues' => $cell['issues'],
			'notes'  => $cell['notes'],
		];
	}

	protected function checks(): array
	{
		$checks = $this->playground->checks($this->filter);
		$counts = [];

		foreach ($checks->count() as $driver => $count) {
			$text = $driver . ' ' . $count['passed'] . ' / ' . array_sum($count);

			if ($count['unsupported'] > 0) {
				$text .= ' · ' . $count['unsupported'] . ' unsupported';
			}

			$counts[] = [
				'text'  => $text,
				'state' => Checks::state($count),
			];
		}

		return $counts;
	}

	protected function drivers(): array
	{
		$drivers = [];

		foreach ($this->playground->drivers() as $name => $info) {
			$library = match (true) {
				$info['available'] === true    => $info['library'] . ' ' . $info['version'],
				isset($info['error']) === true => 'unavailable · ' . $info['error'],
				default                        => 'unavailable'
			};

			$drivers[] = [
				'name'    => $name,
				'library' => $library,
				'read'    => implode(' ', $info['formats']['read'] ?? []),
				'write'   => implode(' ', $info['formats']['write'] ?? []),
			];
		}

		return $drivers;
	}

	protected static function facts(array $facts): string
	{
		$frames = $facts['frames'] === null ? 'animated' : $facts['frames'] . ' fr';

		return implode(' · ', array_filter([
			$facts['format'] ?? 'no image',
			implode('×', $facts['size']),
			$facts['orientation'] === 1 ? null : 'orientation ' . $facts['orientation'],
			$facts['animated'] === true ? $frames : null,
			static::bytes($facts['bytes']),
		]));
	}

	protected function filters(): array
	{
		$issues  = $this->filter->has('issues');
		$links   = [];
		$filters = [
			'source' => array_keys($this->playground->sources),
			'group'  => array_keys($this->playground->cases),
			'driver' => array_keys($this->playground->drivers()),
		];

		foreach ($filters as $key => $values) {
			$links[$key][] = $this->link('all', [$key => null], $this->filter->has($key) === false);

			foreach ($values as $value) {
				$links[$key][] = $this->link($value, [$key => $value], $this->filter->list($key) === [$value]);
			}
		}

		$links['show'] = [
			$this->link('all', ['issues' => null], $issues === false),
			$this->link('issues', ['issues' => ''], $issues),
		];

		return $links;
	}

	protected static function level(float $delta): string
	{
		return match (true) {
			$delta >= Delta::LIMIT     => 'issue',
			$delta >= Delta::LIMIT / 2 => 'notice',
			default                    => 'fine'
		};
	}

	protected function link(
		string $text,
		array $change,
		bool $current
	): array {
		return [
			'text'    => $text,
			'url'     => $this->filter->link('/', $change),
			'current' => $current === true ? 'true' : 'false',
		];
	}

	public function page(): array
	{
		$columns = $this->playground->shown($this->filter);

		return [
			'code'    => $this->playground->cache->fingerprint,
			'refresh' => $this->filter->link('/', ['refresh' => '']),
			'drivers' => $this->drivers(),
			'filters' => $this->filters(),
			'checks'  => $this->checks(),
			'columns' => $columns,
			'span'    => count($columns) + 1,
			'sources' => $this->sources(),
		];
	}

	protected static function percent(float $value): string
	{
		return number_format($value * 100, 1) . ' %';
	}

	protected function row(array $row): array
	{
		$options = [];

		foreach ($row['options'] as $key => $value) {
			$options[] = $key . ': ' . ($value === true ? 'true' : $value);
		}

		return [
			'options' => $options,
			'plan'    => 'plan ' . implode('×', $row['size']),
			'cells'   => array_map($this->cell(...), $row['cells']),
		];
	}

	protected function source(string $name): array
	{
		$facts = $this->playground->facts($name);

		return [
			'name'   => $name,
			'url'    => '/sources/' . $name,
			'link'   => $this->filter->link('/', ['source' => $name]),
			'text'   => $this->playground->sources[$name]['text'],
			'facts'  => static::facts($facts),
			'groups' => [],
		];
	}

	protected function sources(): array
	{
		$sources = [];

		foreach ($this->playground->rows($this->filter) as $row) {
			$name = $row['source'];

			$sources[$name] ??= $this->source($name);
			$sources[$name]['groups'][$row['group']][] = $this->row($row);
		}

		return $sources;
	}
}
