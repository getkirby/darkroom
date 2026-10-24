<?php

namespace Darkroom;

use InvalidArgumentException;

final readonly class Focus
{
	public function __construct(
		public float $x,
		public float $y
	) {
	}

	/**
	 * @throws InvalidArgumentException For an invalid value
	 */
	public static function from(string|Gravity $value): static
	{
		if ($value instanceof Gravity) {
			return $value->focus();
		}

		$focus = static::tryFrom($value);

		if ($focus === null) {
			throw new InvalidArgumentException(
				message: 'Invalid focus: ' . $value
			);
		}

		return $focus;
	}

	public function place(Size $size, Size $canvas): Rect
	{
		$start = fn (
			float $canvas,
			float $size,
			float $focus
		): int => (int)min(
			max(0.0, round($canvas * $focus - $size / 2.0)),
			$canvas - $size
		);

		return new Rect(
			x:      $start($canvas->width, $size->width, $this->x),
			y:      $start($canvas->height, $size->height, $this->y),
			width:  $size->width,
			height: $size->height
		);
	}

	/**
	 * From a Gravity name or `x% y%`
	 */
	public static function tryFrom(string $value): static|null
	{
		$gravity = Gravity::tryFrom($value);

		if ($gravity !== null) {
			return $gravity->focus();
		}

		if (preg_match('/^(-?\d+(?:\.\d+)?)%[\s,]+(-?\d+(?:\.\d+)?)%$/D', $value, $match) !== 1) {
			return null;
		}

		// rounded, so float noise can't move a crop edge by a pixel
		$fraction = fn (string $percent): float => round(min(max((float)$percent / 100.0, 0.0), 1.0), 3);

		return new static(
			x: $fraction($match[1]),
			y: $fraction($match[2])
		);
	}
}
