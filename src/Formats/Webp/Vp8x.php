<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Webp;

use Darkroom\Containers\Bytes;
use Darkroom\Containers\Layout;
use Darkroom\Size;

/**
 * The extended header that the first chunk of any file
 * states: its flags and canvas, and the `VP8X` chunk of them
 *
 * @internal
 */
final class Vp8x
{
	/**
	 * The flags, by the chunk each announces
	 */
	public const array FLAGS = [
		'ALPH' => 0x10,
		'ANIM' => 0x02,
		'EXIF' => 0x08,
		'ICCP' => 0x20,
		'XMP ' => 0x04,
	];

	/**
	 * The `VP8X` chunk of the first chunk's canvas with the flags
	 */
	public static function create(string $chunk, int $flags): string
	{
		$size   = self::size($chunk);
		$width  = substr(pack('V', $size->width - 1), 0, 3);
		$height = substr(pack('V', $size->height - 1), 0, 3);

		return Layout::Riff->chunk('VP8X', chr($flags) . "\0\0\0" . $width . $height);
	}

	/**
	 * Those of an extended file, the alpha flag of a lossless one:
	 * the bit after 14 bits each for width − 1 and height − 1
	 */
	public static function flags(string $chunk): int
	{
		return match (substr($chunk, 0, 4)) {
			'VP8X'  => ord($chunk[8] ?? "\0"),
			'VP8L'  => ord($chunk[12] ?? "\0") & self::FLAGS['ALPH'],
			default => 0
		};
	}

	/**
	 * Size from the start of the first chunk's data
	 */
	public static function size(string $chunk): Size
	{
		$type   = substr($chunk, 0, 4);
		$length = Bytes::int(substr($chunk, 4, 4), little: true);
		$data   = substr($chunk, 8, $length);

		// lossy: frame tag, start code,
		// then width and height in 14 bits of 16 each
		if (
			$type === 'VP8 ' &&
			substr($data, 3, 3) === "\x9D\x01\x2A" &&
			strlen($data) === 10
		) {
			$bits = Bytes::int(substr($data, 6), little: true);

			return new Size($bits & 0x3FFF, $bits >> 16 & 0x3FFF);
		}

		// lossless: signature, then 14 bits
		// each for width − 1 and height − 1
		if (
			$type === 'VP8L' &&
			str_starts_with($data, "\x2F") === true &&
			strlen($data) >= 5
		) {
			$bits = Bytes::int(substr($data, 1, 4), little: true);

			return new Size(
				($bits & 0x3FFF) + 1,
				($bits >> 14 & 0x3FFF) + 1
			);
		}

		// extended: flags, reserved bytes,
		// then 24 bits each for canvas width − 1 and height − 1
		if ($type === 'VP8X' && strlen($data) === 10) {
			$bits = Bytes::int(substr($data, 4), little: true);

			return new Size(($bits & 0xFFFFFF) + 1, ($bits >> 24) + 1);
		}

		return new Size(0, 0);
	}
}
