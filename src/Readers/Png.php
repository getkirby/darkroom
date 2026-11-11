<?php

declare(strict_types = 1);

namespace Darkroom\Readers;

use Darkroom\Orientation;
use Darkroom\Size;
use Darkroom\Tools\Exif;
use Generator;

/**
 * @internal
 */
final class Png extends Reader
{
	/**
	 * Type and data length of each chunk before the image data
	 *
	 * @param resource $handle
	 * @return Generator<string, int>
	 */
	protected static function chunks($handle): Generator
	{
		if (fread($handle, 8) !== "\x89PNG\r\n\x1A\n") {
			return;
		}

		foreach (self::walk($handle, 'png') as $type => $length) {
			if ($type === 'IDAT') {
				return;
			}

			// libpng ignores EXIF data with JPEG's marker
			if ($type === 'eXIf' && Exif::marker($handle) === true) {
				continue;
			}

			yield $type => $length;
		}
	}

	/**
	 * The first `eXIf` chunk
	 *
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		$chunks = self::chunks($handle);
		$length = self::find($chunks, 'eXIf');
		return self::bytes($handle, $length);
	}

	/**
	 * The profile of the first `iCCP` chunk: a name, a null byte,
	 * the compression method and the profile in zlib's format
	 *
	 * @param resource $handle
	 */
	public static function icc($handle): string
	{
		$chunks = self::chunks($handle);
		$length = self::find($chunks, 'iCCP');
		$data   = self::bytes($handle, $length);
		$name   = strpos($data, "\0");

		if ($name === false || function_exists('gzuncompress') === false) {
			return '';
		}

		// stops at about `LIMIT`, however far the data would inflate
		$profile = @gzuncompress(substr($data, $name + 2), self::LIMIT);

		return self::profile((string)$profile);
	}

	/**
	 * Size from the `IHDR` chunk, orientation from the first `eXIf` chunk
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$size        = new Size(0, 0);
		$orientation = null;

		foreach (self::chunks($handle) as $type => $length) {
			// width and height, then bit depth, color type, …
			if ($type === 'IHDR') {
				$data = self::bytes($handle, min($length, 8));

				if (strlen($data) === 8) {
					$size = new Size(
						self::int(substr($data, 0, 4)),
						self::int(substr($data, 4))
					);
				}
			}

			if ($type === 'eXIf' && $orientation === null) {
				$orientation = Exif::orientation($handle);
			}
		}

		return new Header($size, $orientation ?? Orientation::None);
	}
}
