<?php

namespace Darkroom;

/**
 * In pixels, from the top left
 */
final readonly class Rect
{
	public function __construct(
		public int $x,
		public int $y,
		public int $width,
		public int $height
	) {
	}
}
