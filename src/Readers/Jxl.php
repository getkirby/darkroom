<?php

namespace Darkroom\Readers;

use Darkroom\Orientation;
use Darkroom\Size;
use Generator;

/**
 * JPEG XL files: a bare codestream,
 * or one in an ISO BMFF container
 *
 * @internal
 */
final class Jxl extends Reader
{
	/**
	 * Up to 32 bits from `$position` on, lowest first
	 */
	protected static function bits(
		string $data,
		int &$position,
		int $count
	): int {
		$shift     = $position & 7;
		$bytes     = substr($data, $position >> 3, ($shift + $count + 7) >> 3);
		$value     = (self::int($bytes, little: true) >> $shift) & ((1 << $count) - 1);
		$position += $count;

		return $value;
	}

	/**
	 * Type and data length of each box of a container
	 *
	 * @param resource $handle
	 * @return Generator<string, int>
	 */
	protected static function boxes($handle): Generator
	{
		if (fread($handle, 12) === "\0\0\0\x0CJXL \r\n\x87\n") {
			yield from self::walk($handle, 'box');
		}
	}

	/**
	 * The codestream's first 64 bytes,
	 * more than its headers need,
	 * bare or from `jxlc` or `jxlp` boxes
	 *
	 * @param resource $handle
	 */
	protected static function codestream($handle): string
	{
		$data = (string)fread($handle, 64);

		if (str_starts_with($data, "\xFF\x0A") === true) {
			return $data;
		}

		$data = '';

		fseek($handle, 0);

		foreach (self::boxes($handle) as $type => $length) {
			// partial codestreams start with their index
			if ($type === 'jxlp') {
				$part  = self::bytes($handle, min($length, 68 - strlen($data)));
				$data .= substr($part, 4);
			}

			if ($type === 'jxlc') {
				$data .= self::bytes($handle, min($length, 64 - strlen($data)));
			}

			if (strlen($data) >= 64) {
				break;
			}
		}

		return $data;
	}

	/**
	 * The `Exif` box; not one compressed in a `brob` box
	 *
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		$boxes  = self::boxes($handle);
		$length = self::find($boxes, 'Exif');
		return self::tiff($handle, $length, offset: true);
	}

	/**
	 * Size, orientation and animation from the codestream's headers
	 *
	 * @param resource $handle
	 * @return array{Header, bool}
	 */
	protected static function header($handle): array
	{
		$data = self::codestream($handle);
		$end  = strlen($data) * 8;

		if (str_starts_with($data, "\xFF\x0A") === false) {
			return [new Header(), false];
		}

		// after the signature
		$position = 16;
		$size     = self::size($data, $position);

		if ($position > $end) {
			return [new Header(), false];
		}

		// image metadata: all default, extra fields,
		// orientation, intrinsic size, preview, animation
		if (
			self::bits($data, $position, 1) === 1 ||
			self::bits($data, $position, 1) === 0
		) {
			return [new Header($size), false];
		}

		$orientation = self::bits($data, $position, 3) + 1;

		if (self::bits($data, $position, 1) === 1) {
			self::size($data, $position);
		}

		if (self::bits($data, $position, 1) === 1) {
			self::preview($data, $position);
		}

		$animated = self::bits($data, $position, 1) === 1;

		if ($position > $end) {
			return [new Header($size), false];
		}

		return [new Header($size, Orientation::from($orientation)), $animated];
	}

	/**
	 * @param resource $handle
	 */
	public static function isAnimated($handle): bool
	{
		return self::header($handle)[1];
	}

	/**
	 * Skips a preview header: in multiples of 8 or not,
	 * the height, a ratio, the width without one
	 */
	protected static function preview(string $data, int &$position): void
	{
		$bits = self::bits($data, $position, 1) === 1 ? [0, 0, 5, 9] : [6, 8, 10, 12];

		$position += $bits[self::bits($data, $position, 2)];

		if (self::bits($data, $position, 3) === 0) {
			$position += $bits[self::bits($data, $position, 2)];
		}
	}

	/**
	 * The orientation is in the codestream, not in EXIF data
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		return self::header($handle)[0];
	}

	/**
	 * Height or width: for small images 5 bits of multiples of 8,
	 * else 2 bits for how many follow
	 */
	protected static function side(
		string $data,
		int &$position,
		bool $small
	): int {
		if ($small === true) {
			return (self::bits($data, $position, 5) + 1) * 8;
		}

		$index = self::bits($data, $position, 2);
		return self::bits($data, $position, [9, 13, 18, 30][$index]) + 1;
	}

	/**
	 * A size header: whether it is small, the height,
	 * then the width or its ratio to the height
	 */
	protected static function size(string $data, int &$position): Size
	{
		$small  = self::bits($data, $position, 1) === 1;
		$height = self::side($data, $position, $small);
		$width  = match (self::bits($data, $position, 3)) {
			0       => self::side($data, $position, $small),
			1       => $height,
			2       => intdiv($height * 12, 10),
			3       => intdiv($height * 4, 3),
			4       => intdiv($height * 3, 2),
			5       => intdiv($height * 16, 9),
			6       => intdiv($height * 5, 4),
			default => $height * 2
		};

		return new Size($width, $height);
	}
}
