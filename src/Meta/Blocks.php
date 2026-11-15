<?php

declare(strict_types = 1);

namespace Darkroom\Meta;

/**
 * What a writer writes into an image, as its meta
 *
 * @internal
 */
final readonly class Blocks
{
	/**
	 * @param $icc A profile instead of the image's own, `''` for none
	 * @param $exif TIFF data
	 */
	public function __construct(
		public string|null $icc = null,
		public string $exif = ''
	) {
	}
}
