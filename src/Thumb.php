<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * What the output should be
 */
final readonly class Thumb
{
	/**
	 * @param $crop Gravity name or `x% y%`
	 * @param $blur Gaussian sigma in output pixels, 1–100
	 * @param $sharpen Amount, 1–100
	 * @param $quality 1–100
	 */
	public function __construct(
		public int|null $width = null,
		public int|null $height = null,
		public string|null $crop = null,
		public int|null $blur = null,
		public bool $grayscale = false,
		public int|null $sharpen = null,
		public int|null $quality = null,
		public Format|null $format = null,
		public bool|null $interlace = null,
	) {
	}

	private static function error(
		string $key,
		mixed $value
	): InvalidArgumentException {
		$value = is_scalar($value) === true ? var_export($value, true) : get_debug_type($value);

		return new InvalidArgumentException(
			message: 'Invalid ' . $key . ': ' . $value
		);
	}

	/**
	 * @internal
	 */
	public static function flag(string $key, mixed $value): bool|null
	{
		if ($value === null || $value === '') {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw static::error($key, $value);
	}

	public function focus(): Focus|null
	{
		if ($this->crop === null) {
			return null;
		}

		return Focus::from($this->crop);
	}

	/**
	 * From options as configuration or a URL gives
	 * them; unknown keys are ignored
	 *
	 * @throws InvalidArgumentException For an invalid value of a known key
	 */
	public static function from(array $options): static
	{
		$options['grayscale'] ??= $options['greyscale'] ?? $options['bw'] ?? null;

		// values that set nothing; `false` turns `interlace` off
		foreach ($options as $key => $value) {
			$nothing = $key === 'interlace' ? [''] : ['', false, 0];

			if (in_array($value, $nothing, true) === true) {
				$options[$key] = null;
			}
		}

		$blur    = $options['blur'] ?? null;
		$crop    = $options['crop'] ?? null;
		$format  = $options['format'] ?? null;
		$sharpen = $options['sharpen'] ?? null;

		return new static(
			width: static::number(
				'width',
				$options['width'] ?? null
			),
			height: static::number(
				'height',
				$options['height'] ?? null
			),
			crop: static::position(
				$crop === true ? Gravity::Center : $crop
			),
			blur: static::number(
				'blur',
				$blur === true ? 10 : $blur,
				100
			),
			grayscale: static::flag(
				'grayscale',
				$options['grayscale']
			) === true,
			sharpen: static::number(
				'sharpen',
				$sharpen === true ? 50 : $sharpen,
				100
			),
			quality: static::number(
				'quality',
				$options['quality'] ?? null,
				100
			),
			format: match (true) {
				is_string($format)        => Format::from($format),
				$format === null,
				$format instanceof Format => $format,
				default                   => throw static::error('format', $format)
			},
			interlace: static::flag(
				'interlace',
				$options['interlace'] ?? null
			)
		);
	}

	private static function number(
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
	 * @throws InvalidArgumentException For an invalid crop
	 */
	private static function position(mixed $value): string|null
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof Gravity) {
			return $value->value;
		}

		if (is_string($value) === false) {
			throw static::error('crop', $value);
		}

		Focus::from($value);

		return $value;
	}

	/**
	 * Plain values that `from()` reads back
	 */
	public function toArray(): array
	{
		$array = [
			'width'     => $this->width,
			'height'    => $this->height,
			'crop'      => $this->crop,
			'blur'      => $this->blur,
			'grayscale' => $this->grayscale === true ? true : null,
			'sharpen'   => $this->sharpen,
			'quality'   => $this->quality,
			'format'    => $this->format?->extension(),
			'interlace' => $this->interlace,
		];

		return array_filter($array, fn ($value) => $value !== null);
	}
}
