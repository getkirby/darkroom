<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * The options a `Darkroom` sets for every thumb: how it is written;
 * the constants apply where neither the thumb nor the darkroom says
 */
readonly class Defaults
{
	public const bool ICC = true;
	public const bool INTERLACE = false;
	public const int QUALITY = 90;

	/**
	 * @param $quality 1–100
	 * @param $icc Keep the color profile
	 */
	public function __construct(
		public int|null $quality = null,
		public Format|null $format = null,
		public bool|null $interlace = null,
		public bool|null $icc = null,
	) {
	}

	/**
	 * Values that set nothing as `null`; `false` turns a flag off
	 */
	protected static function clean(array $values): array
	{
		foreach ($values as $key => $value) {
			$nothing = in_array($key, ['icc', 'interlace'], true) === true ? [''] : ['', false, 0];

			if (in_array($value, $nothing, true) === true) {
				$values[$key] = null;
			}
		}

		return $values;
	}

	protected static function error(
		string $key,
		mixed $value
	): InvalidArgumentException {
		$value = is_scalar($value) === true ? var_export($value, true) : get_debug_type($value);

		return new InvalidArgumentException(
			message: 'Invalid ' . $key . ': ' . $value
		);
	}

	protected static function flag(string $key, mixed $value): bool|null
	{
		if ($value === null || $value === '') {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw static::error($key, $value);
	}

	/**
	 * From defaults as configuration gives them; unknown keys are ignored
	 *
	 * @throws InvalidArgumentException For an invalid value of a known key
	 */
	public static function from(Defaults|array $values): self
	{
		if ($values instanceof self) {
			return $values;
		}

		$values = static::clean($values);
		$values = static::read($values);

		return new self(...$values);
	}

	protected static function number(
		string $key,
		mixed $value,
		int $max = PHP_INT_MAX
	): int|null {
		if ($value === null) {
			return null;
		}

		$number = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 0]
		]);

		// filter_var() reads `true` as 1
		if ($number === false || $value === true) {
			throw static::error($key, $value);
		}

		if ($number === 0) {
			return null;
		}

		return min($number, $max);
	}

	/**
	 * The defaults among cleaned `$values`, as constructor arguments
	 *
	 * @return array{quality: int|null, format: Format|null, interlace: bool|null, icc: bool|null}
	 * @throws InvalidArgumentException For an invalid value
	 */
	protected static function read(array $values): array
	{
		$format = $values['format'] ?? null;

		return [
			'quality' => static::number(
				'quality',
				$values['quality'] ?? null,
				100
			),
			'format' => match (true) {
				is_string($format)        => Format::from($format),
				$format === null,
				$format instanceof Format => $format,
				default                   => throw static::error('format', $format)
			},
			'interlace' => static::flag(
				'interlace',
				$values['interlace'] ?? null
			),
			'icc' => static::flag(
				'icc',
				$values['icc'] ?? null
			)
		];
	}

	/**
	 * Plain values that `from()` reads back
	 */
	public function toArray(): array
	{
		$array = [
			'quality'   => $this->quality,
			'format'    => $this->format?->extension(),
			'interlace' => $this->interlace,
			'icc'       => $this->icc,
		];

		return array_filter($array, fn ($value) => $value !== null);
	}
}
