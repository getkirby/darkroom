<?php

namespace Darkroom;

/**
 * How the thumb is written
 */
final readonly class Output
{
	/**
	 * @param $quality 1–100
	 * @param $icc Keep the color profile
	 */
	public function __construct(
		public Format $format,
		public int $quality,
		public bool $interlace,
		public bool $icc
	) {
	}
}
