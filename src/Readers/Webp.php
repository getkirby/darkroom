<?php

namespace Darkroom\Readers;

use Darkroom\Size;

/**
 * @internal
 */
final class Webp extends Reader
{
	/**
	 * The flags of an extended file's metadata chunks
	 */
	protected const array FLAGS = [
		'EXIF' => 0x08,
		'ICCP' => 0x20,
	];

	/**
	 * Header and first 10 data bytes of the first chunk
	 * (`VP8 `, `VP8L` or `VP8X`); `''` for no WebP file
	 *
	 * @param resource $handle
	 */
	protected static function chunk($handle): string
	{
		$head = (string)fread($handle, 30);

		if (
			str_starts_with($head, 'RIFF') === false ||
			substr($head, 8, 4) !== 'WEBP'
		) {
			return '';
		}

		return substr($head, 12);
	}

	/**
	 * The `EXIF` chunk
	 *
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		$chunk  = self::chunk($handle);
		$length = self::locate($handle, $chunk, 'EXIF');
		return self::tiff($handle, $length);
	}

	/**
	 * The flags of an extended file
	 */
	protected static function flags(string $chunk): int
	{
		if (str_starts_with($chunk, 'VP8X') === false) {
			return 0;
		}

		return ord($chunk[8] ?? "\0");
	}

	/**
	 * The `ICCP` chunk
	 *
	 * @param resource $handle
	 */
	public static function icc($handle): string
	{
		$chunk   = self::chunk($handle);
		$length  = self::locate($handle, $chunk, 'ICCP');
		$profile = self::bytes($handle, $length);
		return self::profile($profile);
	}

	/**
	 * An extended file with the animation flag
	 *
	 * @param resource $handle
	 */
	public static function isAnimated($handle): bool
	{
		$chunk = self::chunk($handle);
		$flags = self::flags($chunk);
		return ($flags & 0x02) === 0x02;
	}

	/**
	 * Data length of the `EXIF` or `ICCP` chunk,
	 * with the handle at its data;
	 * libwebp needs the flag for it
	 *
	 * @param resource $handle
	 * @param key-of<self::FLAGS> $type
	 */
	protected static function locate(
		$handle,
		string $chunk,
		string $type
	): int {
		if ((self::flags($chunk) & self::FLAGS[$type]) === 0) {
			return 0;
		}

		fseek($handle, 12);

		$chunks = self::walk($handle, 'riff');
		return self::find($chunks, $type);
	}

	/**
	 * Size from the first chunk, orientation from the `EXIF` chunk
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$chunk = self::chunk($handle);
		$size  = self::size($chunk);

		if (self::locate($handle, $chunk, 'EXIF') === 0) {
			return new Header($size);
		}

		// some writers keep JPEG's marker
		Exif::marker($handle);

		$exif = Exif::read($handle);
		return new Header($size, $exif->orientation);
	}

	/**
	 * Size from the start of the first chunk's data
	 */
	public static function size(string $chunk): Size
	{
		$type   = substr($chunk, 0, 4);
		$length = self::int(substr($chunk, 4, 4), little: true);
		$data   = substr($chunk, 8, $length);

		// lossy: frame tag, start code,
		// then width and height in 14 bits each
		if (
			$type === 'VP8 ' &&
			substr($data, 3, 3) === "\x9D\x01\x2A" &&
			strlen($data) === 10
		) {
			return new Size(
				self::int(substr($data, 6, 2), little: true) & 0x3FFF,
				self::int(substr($data, 8, 2), little: true) & 0x3FFF
			);
		}

		// lossless: signature, then 14 bits
		// each for width − 1 and height − 1
		if (
			$type === 'VP8L' &&
			str_starts_with($data, "\x2F") === true &&
			strlen($data) >= 5
		) {
			$bits = self::int(substr($data, 1, 4), little: true);

			return new Size(
				($bits & 0x3FFF) + 1,
				($bits >> 14 & 0x3FFF) + 1
			);
		}

		// extended: flags, reserved bytes,
		// then 24 bits each for canvas width − 1 and height − 1
		if ($type === 'VP8X' && strlen($data) === 10) {
			return new Size(
				self::int(substr($data, 4, 3), little: true) + 1,
				self::int(substr($data, 7, 3), little: true) + 1
			);
		}

		return new Size(0, 0);
	}
}
