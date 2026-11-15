<?php

declare(strict_types = 1);

namespace Darkroom;

use InvalidArgumentException;

/**
 * Options a `Darkroom` sets for every thumb
 */
readonly class Defaults
{
	public const bool INTERLACE = false;
	public const array META = [Meta::Color];
	public const int QUALITY = 85;

	/**
	 * @param $quality SSIMULACRA2 score, 1–100
	 * @param list<Meta>|null $meta What thumbs keep of their source
	 */
	public function __construct(
		public int|null $quality = null,
		public Format|null $format = null,
		public bool|null $interlace = null,
		public array|null $meta = null,
	) {
	}

	protected static function error(
		string $key,
		mixed $value
	): never {
		$value = is_scalar($value) === true ? var_export($value, true) : get_debug_type($value);

		throw new InvalidArgumentException(
			message: 'Invalid ' . $key . ': ' . $value
		);
	}

	protected static function flag(string $key, mixed $value): bool|null
	{
		if ($value === null || $value === '') {
			return null;
		}

		$flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		if ($flag === null) {
			static::error($key, $value);
		}

		return $flag;
	}

	protected static function format(mixed $format): Format|null
	{
		if (in_array($format, [null, '', false, 0], true) === true) {
			return null;
		}

		if ($format instanceof Format) {
			return $format;
		}

		if (is_string($format) === false) {
			static::error('format', $format);
		}

		return Format::from($format);
	}

	public static function from(Defaults|array $options): self
	{
		if ($options instanceof self) {
			return $options;
		}

		return new self(
			quality:   static::number('quality', $options['quality'] ?? null, 100),
			format:    self::format($options['format'] ?? null),
			interlace: static::flag('interlace', $options['interlace'] ?? null),
			meta:      static::meta($options['meta'] ?? null)
		);
	}

	/**
	 * Cases or their values, in the order of the cases, each once
	 *
	 * @return list<Meta>|null
	 */
	protected static function meta(mixed $meta): array|null
	{
		if ($meta === null || $meta === '') {
			return null;
		}

		if (is_array($meta) === false) {
			static::error('meta', $meta);
		}

		$list = [];

		foreach ($meta as $category) {
			$list[] = match (true) {
				$category instanceof Meta => $category,
				is_string($category) === true => Meta::tryFrom($category) ?? static::error('meta', $category),
				default                       => static::error('meta', $category)
			};
		}

		$cases = array_filter(
			Meta::cases(),
			fn (Meta $case) => in_array($case, $list, true)
		);

		return array_values($cases);
	}

	protected static function number(
		string $key,
		mixed $value,
		int $max = PHP_INT_MAX
	): int|null {
		if (in_array($value, [null, '', false, 0], true) === true) {
			return null;
		}

		$number = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 0]
		]);

		if ($number === false || $value === true) {
			static::error($key, $value);
		}

		if ($number === 0) {
			return null;
		}

		return min($number, $max);
	}

	public function toArray(): array
	{
		$array = [
			'quality'   => $this->quality,
			'format'    => $this->format?->extension(),
			'interlace' => $this->interlace,
			'meta'      => $this->meta === null ? null : array_column($this->meta, 'value'),
		];

		return array_filter($array, fn ($value) => $value !== null);
	}
}
