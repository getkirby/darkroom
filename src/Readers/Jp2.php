<?php

declare(strict_types = 1);

namespace Darkroom\Readers;

use Darkroom\Size;

/**
 * JPEG 2000 files: a JP2 container or a bare codestream
 *
 * @internal
 */
final class Jp2 extends Reader
{
	/**
	 * From the container's image header or the codestream's
	 * size marker; JPEG 2000 has no orientation
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$data = (string)fread($handle, 24);

		// SOC, then SIZ: the reference grid and the image's offset on it
		if (str_starts_with($data, "\xFF\x4F\xFF\x51") === true && strlen($data) === 24) {
			$width  = self::int(substr($data, 8, 4)) - self::int(substr($data, 16, 4));
			$height = self::int(substr($data, 12, 4)) - self::int(substr($data, 20, 4));
			$size   = new Size(max(0, $width), max(0, $height));

			return new Header($size);
		}

		// after the signature box, `jp2h` holds `ihdr`: height, then width
		self::seek($handle, 12);

		$boxes  = self::walk($handle, 'box');
		$length = self::find($boxes, 'jp2h');
		$boxes  = self::walk($handle, 'box', $length);
		$length = self::find($boxes, 'ihdr');
		$data   = self::bytes($handle, min($length, 8));

		// not from an image header that is cut off
		if (strlen($data) < 8) {
			return new Header();
		}

		$size = new Size(
			self::int(substr($data, 4, 4)),
			self::int(substr($data, 0, 4))
		);

		return new Header($size);
	}
}
