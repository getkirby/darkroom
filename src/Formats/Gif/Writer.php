<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Gif;

use Darkroom\Containers\Splice;
use Darkroom\Formats\Writer as Base;
use Darkroom\Meta\Blocks;

/**
 * @internal
 */
final class Writer extends Base
{
	/**
	 * The application extensions of the image:
	 * loops and the color profile
	 */
	protected const array KEEP = ['ANIMEXTS1.0', 'ICCRGBG1012', 'NETSCAPE2.0'];

	/**
	 * Keeps the header, images, graphic controls, plain text and
	 * the application extensions in `KEEP`, no comments;
	 * ends with the trailer, also where the file ends early
	 *
	 * @param resource $handle
	 * @param resource $out
	 */
	public static function write(
		$handle,
		$out,
		Blocks $blocks
	): void {
		$splice = new Splice($handle, $out);
		$from   = null;

		// each part kept up to the start of the next
		foreach (Reader::blocks($handle) as $kind => [$start, $head]) {
			if ($from !== null) {
				$splice->copy($from, $start);
			}

			$from = match ($kind) {
				'GIF', ',', "!\x01", "!\xF9" => $start,
				"!\xFF"                     => in_array($head, self::KEEP, true) === true ? $start : null,
				default                     => null
			};
		}

		$splice->write(';');
		$splice->flush();
	}
}
