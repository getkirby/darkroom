<?php

namespace Darkroom\Playground;

final class Filter
{
	private const array KEYS = [
		'source',
		'group',
		'case',
		'driver',
		'issues'
	];

	/**
	 * @param array<string, string> $query
	 */
	public function __construct(
		public readonly array $query = []
	) {
	}

	public static function from(array $query): static
	{
		$query = array_intersect_key($query, array_flip(self::KEYS));
		$query = array_filter($query, is_string(...));

		return new static($query);
	}

	public function has(string $key): bool
	{
		return isset($this->query[$key]) === true;
	}

	public function link(string $path, array $change = []): string
	{
		$query = array_filter([...$this->query, ...$change], fn ($value): bool => $value !== null);
		$query = http_build_query($query);

		return $query === '' ? $path : $path . '?' . $query;
	}

	public function list(string $key): array|null
	{
		$value = $this->query[$key] ?? '';

		return $value === '' ? null : explode(',', $value);
	}

	public function select(string $key, array $values): array
	{
		$list = $this->list($key);

		return $list === null ? $values : array_values(array_intersect($values, $list));
	}

	public function without(string $key): static
	{
		return new static(array_diff_key($this->query, [$key => true]));
	}
}
