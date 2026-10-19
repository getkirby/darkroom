<?php

namespace Darkroom\Tools;

/**
 * A gaussian sharpen with a sigma of the amount in hundredths,
 * as a 3×3 kernel: the pixel twice, less its blur
 *
 * @internal
 */
final class Sharpen
{
	/**
	 * The kernel and the divisor that keeps its sum at 1
	 *
	 * @return array{list<list<float>>, float}
	 */
	public static function kernel(int $amount): array
	{
		$sigma  = (float)$amount / 100.0;
		$edge   = exp(-0.5 / ($sigma * $sigma));
		$corner = $edge * $edge;
		$sum    = 1.0 + 4.0 * ($edge + $corner);
		$kernel = [
			[-$corner, -$edge, -$corner],
			[-$edge, 2.0 * $sum, -$edge],
			[-$corner, -$edge, -$corner],
		];

		return [$kernel, $sum + 1.0];
	}
}
