<?php

declare(strict_types = 1);

namespace Darkroom\Readers;

use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Size;

/**
 * Size as stored, orientation from a reader,
 * format from `Reader::open()`
 *
 * @internal
 */
final readonly class Header
{
	public function __construct(
		public Size $size = new Size(0, 0),
		public Orientation $orientation = Orientation::None,
		public Format|null $format = null
	) {
	}
}
