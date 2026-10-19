<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * A point in relative coordinates, 0.0–1.0 from the top left
 */
final readonly class Focus
{
	public function __construct(
		public float $x,
		public float $y
	) {
	}

	/**
	 * From a Gravity, its name or `x% y%`
	 *
	 * @throws InvalidArgumentException For anything else
	 */
	public static function from(string|Gravity $value): static
	{
		if (is_string($value) === true) {
			$value = Gravity::tryFrom($value) ?? $value;
		}

		if ($value instanceof Gravity) {
			return $value->focus();
		}

		if (preg_match('/^(-?\d+(?:\.\d+)?)%[\s,]+(-?\d+(?:\.\d+)?)%$/D', $value, $match) !== 1) {
			throw new InvalidArgumentException(
				message: 'Invalid focus: ' . $value
			);
		}

		// rounded, so float noise can't move a crop edge by a pixel
		$fraction = fn (string $percent): float => round(min(max((float)$percent / 100.0, 0.0), 1.0), 3);

		return new static(
			x: $fraction($match[1]),
			y: $fraction($match[2])
		);
	}
}
