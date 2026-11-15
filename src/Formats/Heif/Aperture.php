<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Heif;

use Darkroom\Containers\Bytes;
use Darkroom\Containers\FullBox;
use Darkroom\Rect;
use Darkroom\Size;

/**
 * The clean aperture within the coded image
 *
 * @internal
 */
final class Aperture
{
	/**
	 * The coded size and the clean aperture within it
	 *
	 * @param list<array{string, string}> $properties
	 * @return array{Size, Rect}
	 */
	public static function of(array $properties): array
	{
		$ispe = '';
		$clap = '';

		foreach ($properties as [$type, $data]) {
			match ($type) {
				'clap'  => $clap = $data,
				'ispe'  => $ispe = $data,
				default => null
			};
		}

		$box    = new FullBox($ispe);
		$width  = $box->int(4);
		$height = $box->int(4);
		$image  = new Size($width, $height);

		if ($clap === '') {
			return [$image, new Rect(0, 0, $width, $height)];
		}

		[$left, $right] = self::span($clap, $width, horizontal: true);
		[$top, $bottom] = self::span($clap, $height, horizontal: false);

		$aperture = new Rect(
			x:      $left,
			y:      $top,
			width:  max(0, $right - $left + 1),
			height: max(0, $bottom - $top + 1)
		);

		return [$image, $aperture];
	}

	/**
	 * First and last pixel of the clean aperture on one axis,
	 * clamped to the image; rounded as libheif does:
	 * horizontally down, vertically to the nearest
	 *
	 * @return array{int, int}
	 */
	public static function span(
		string $clap,
		int $image,
		bool $horizontal
	): array {
		// width or height a / b,
		// the centre's offset c / d:
		// signed numerators, denominators ≥ 1
		$axis   = $horizontal === true ? 0 : 8;
		$length = Bytes::int(substr($clap, $axis, 8));
		$offset = Bytes::int(substr($clap, 16 + $axis, 8));
		$a      = $length >> 32;
		$b      = max(1, $length & 0xFFFFFFFF);
		$c      = $offset >> 32;
		$d      = max(1, $offset & 0xFFFFFFFF);

		// in one division, so that halves and thirds stay exact
		$start = fdiv(2 * $c * $b + ($image * $b - $a) * $d, 2 * $d * $b);
		$first = (int)($horizontal === true ? $start : $start + 0.5);
		$last  = $first + (int)floor(fdiv($a, $b) - 0.5);

		return [max(0, $first), min($image - 1, $last)];
	}
}
