<?php

declare(strict_types = 1);

namespace Darkroom\Readers;

use Darkroom\Rect;
use Darkroom\Size;
use Generator;

/**
 * @internal
 */
final class Gif extends Reader
{
	/**
	 * The logical screen around the first image,
	 * which some decoders return on its own
	 *
	 * @param resource $handle
	 */
	public static function canvas($handle): Rect|null
	{
		$screen = self::screen($handle);
		$images = self::images($handle, $screen);
		$image  = (string)$images->current();

		if (strlen($image) < 8) {
			return null;
		}

		$left   = self::int(substr($image, 0, 2), little: true);
		$top    = self::int(substr($image, 2, 2), little: true);
		$size   = self::size($screen);
		$frame  = self::size(substr($image, 4));
		$canvas = new Rect(-$left, -$top, $size->width, $size->height);

		if ($canvas == new Rect(0, 0, $frame->width, $frame->height)) {
			return null;
		}

		return $canvas;
	}

	/**
	 * Position, size and flags of each image,
	 * past at most 1 000 000 blocks and sub-blocks
	 *
	 * @param resource $handle
	 * @return Generator<int, string>
	 */
	protected static function images($handle, string $screen): Generator
	{
		if (strlen($screen) < 7) {
			return;
		}

		// after the global color table
		$table = self::table(ord($screen[4]));
		self::seek($handle, 13 + $table);

		for ($step = 0; $step < 1_000_000; $step++) {
			$byte = (string)fread($handle, 1);

			// image descriptor, then the local color table
			// and the LZW code size
			if ($byte === "\x2C") {
				$descriptor = self::bytes($handle, 9);
				$table      = self::table(ord($descriptor[8] ?? "\0"));
				$next       = (int)ftell($handle) + $table + 1;

				yield $descriptor;

				self::seek($handle, $next);
			} elseif ($byte === "\x21") {
				// extension: its label
				fseek($handle, 1, SEEK_CUR);
			} else {
				// the trailer, or anything else
				return;
			}

			// data sub-blocks: length and data,
			// up to a length of 0
			while (
				$step++ < 1_000_000 &&
				($length = self::int((string)fread($handle, 1))) > 0
			) {
				fseek($handle, $length, SEEK_CUR);
			}
		}
	}

	/**
	 * Two or more images
	 *
	 * @param resource $handle
	 */
	public static function isAnimated($handle): bool
	{
		$screen = self::screen($handle);
		$images = self::images($handle, $screen);

		// to the second one
		$images->next();

		return $images->valid();
	}

	/**
	 * Size from the logical screen descriptor
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$screen = self::screen($handle);

		if (strlen($screen) < 4) {
			return new Header();
		}

		$size = self::size($screen);

		return new Header($size);
	}

	/**
	 * The logical screen's width, height,
	 * flags and more; `''` for no GIF
	 *
	 * @param resource $handle
	 */
	protected static function screen($handle): string
	{
		$head = (string)fread($handle, 13);

		if (in_array(substr($head, 0, 6), ['GIF87a', 'GIF89a'], true) === false) {
			return '';
		}

		return substr($head, 6);
	}

	/**
	 * Width and height at the start of the data
	 */
	protected static function size(string $data): Size
	{
		return new Size(
			self::int(substr($data, 0, 2), little: true),
			self::int(substr($data, 2, 2), little: true)
		);
	}

	/**
	 * Length of the color table that the flags announce
	 */
	protected static function table(int $flags): int
	{
		return ($flags & 0x80) === 0x80 ? 3 << ($flags & 0x07) + 1 : 0;
	}
}
