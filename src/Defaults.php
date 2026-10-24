<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * Options a `Darkroom` sets for every thumb
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
			icc:       static::flag('icc', $options['icc'] ?? null)
		);
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
			'icc'       => $this->icc,
		];

		return array_filter($array, fn ($value) => $value !== null);
	}
}
