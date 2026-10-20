<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * What the thumb should be: geometry and filters on top of what a darkroom defaults
 */
final readonly class Options extends Defaults
{
	/**
	 * @param $crop Gravity name or `x% y%`
	 * @param $blur Gaussian sigma in output pixels, 1–100
	 * @param $sharpen Amount, 1–100
	 * @param $quality 1–100
	 * @param $icc Keep the color profile
	 */
	public function __construct(
		public int|null $width = null,
		public int|null $height = null,
		public string|null $crop = null,
		public int|null $blur = null,
		public bool $grayscale = false,
		public int|null $sharpen = null,
		int|null $quality = null,
		Format|null $format = null,
		bool|null $interlace = null,
		bool|null $icc = null,
	) {
		parent::__construct($quality, $format, $interlace, $icc);
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
	public static function from(Defaults|array $values): static
	{
		if ($values instanceof static) {
			return $values;
		}

		if ($values instanceof Defaults) {
			$values = $values->toArray();
		}

		$values['grayscale'] ??= $values['greyscale'] ?? $values['bw'] ?? null;

		$values  = static::clean($values);
		$base     = static::read($values);
		$blur     = $values['blur'] ?? null;
		$crop     = $values['crop'] ?? null;
		$sharpen  = $values['sharpen'] ?? null;

		return new static(
			...$base,
			width: static::number(
				'width',
				$values['width'] ?? null
			),
			height: static::number(
				'height',
				$values['height'] ?? null
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
				$values['grayscale']
			) === true,
			sharpen: static::number(
				'sharpen',
				$sharpen === true ? 50 : $sharpen,
				100
			)
		);
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
			...parent::toArray()
		];

		return array_filter($array, fn ($value) => $value !== null);
	}
}
