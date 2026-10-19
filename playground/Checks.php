<?php

namespace Darkroom\Playground;

final class Checks
{
	private const array COUNT = [
		'passed'      => 0,
		'issues'      => 0,
		'unsupported' => 0
	];

	public function __construct(
		private readonly Playground $playground,
		private readonly Filter $filter,
	) {
	}

	public function count(): array
	{
		$counts = array_fill_keys($this->playground->shown($this->filter), self::COUNT);
		$rows   = $this->playground->rows($this->filter->without('issues'));

		foreach ($rows as $row) {
			foreach ($row['cells'] as $driver => $cell) {
				$counts[$driver][$cell['state']]++;
			}
		}

		return $counts;
	}

	public static function state(array $count): string
	{
		return $count['issues'] > 0 ? 'issues' : 'passed';
	}
}
