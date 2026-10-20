<?php

namespace Darkroom\Readers;

use Closure;
use Darkroom\Format;
use Darkroom\Rect;
use Generator;

/**
 * Reads one container format from the start of a file,
 * no more than it needs
 *
 * @internal
 */
abstract class Reader
{
	protected const int LIMIT = 1_048_576;
	public const int STEPS = 10_000;

	/**
	 * The color signal of a file without one, as libheif's:
	 * unspecified primaries, transfer and matrix, full range
	 */
	public const array UNSPECIFIED = [2, 2, 2, 1];

	/**
	 * Up to `$length` bytes, at most `LIMIT`
	 *
	 * @param resource $handle
	 */
	public static function bytes($handle, int $length): string
	{
		if ($length < 1) {
			return '';
		}

		return (string)stream_get_contents($handle, min($length, self::LIMIT));
	}

	/**
	 * @param resource $handle
	 */
	public static function canvas($handle): Rect|null
	{
		return null;
	}

	/**
	 * The color signal: primaries, transfer, matrix and full range (H.273)
	 *
	 * @param resource $handle
	 * @return array{int, int, int, int}
	 */
	public static function cicp($handle): array
	{
		return self::UNSPECIFIED;
	}

	/**
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		return '';
	}

	/**
	 * Data length of the first chunk or box of the type,
	 * with the handle at its data
	 *
	 * @param Generator<string, int> $walk
	 */
	public static function find(Generator $walk, string $type): int
	{
		foreach ($walk as $found => $length) {
			if ($found === $type) {
				return $length;
			}
		}

		return 0;
	}

	/**
	 * @return class-string<Reader>
	 */
	public static function for(Format $format): string
	{
		return match ($format) {
			Format::Avif,
			Format::Heic => Heif::class,
			Format::Gif  => Gif::class,
			Format::Jp2  => Jp2::class,
			Format::Jpeg => Jpeg::class,
			Format::Jxl  => Jxl::class,
			Format::Png  => Png::class,
			Format::Webp => Webp::class,
		};
	}

	/**
	 * The ICC color profile
	 *
	 * @param resource $handle
	 */
	public static function icc($handle): string
	{
		return '';
	}

	/**
	 * An unsigned integer from big-endian or
	 * little-endian bytes; missing bytes count as zero
	 */
	public static function int(string $bytes, bool $little = false): int
	{
		if (strlen($bytes) === 1) {
			return ord($bytes);
		}

		// `unpack()` formats, big-endian, then little-endian
		$format = match (strlen($bytes)) {
			2       => 'nv',
			4       => 'NV',
			default => 'JP'
		};

		// other lengths as 8 bytes, padded, or the lowest 8
		if ($format === 'JP') {
			$bytes = match ($little) {
				true  => str_pad(substr($bytes, 0, 8), 8, "\0"),
				false => str_pad(substr($bytes, -8), 8, "\0", STR_PAD_LEFT)
			};
		}

		/** @var array{1: int} $value */
		$value = unpack($format[(int)$little], $bytes);

		return $value[1];
	}

	/**
	 * @param resource $handle
	 */
	public static function isAnimated($handle): bool
	{
		return false;
	}

	/**
	 * Runs `$call` with the format's reader,
	 * a handle at the start of the file and the format
	 *
	 * @template T
	 * @param Closure(class-string<Reader>, resource, Format): T $call
	 * @return T|null
	 */
	public static function open(string $file, Closure $call): mixed
	{
		// fopen() throws for an empty path
		$handle = $file === '' ? false : @fopen($file, 'rb');

		if ($handle === false) {
			return null;
		}

		try {
			// a directory opens, but can't be read
			$format = Format::detect((string)@fread($handle, 64));

			if ($format === null) {
				return null;
			}

			rewind($handle);

			$reader = self::for($format);

			return $call($reader, $handle, $format);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * The ICC profile at the start of the data,
	 * up to the length its header states
	 */
	public static function profile(string $data): string
	{
		$length = self::int(substr($data, 0, 4));

		// at least the header and the number of tags,
		// and the header's signature
		if (
			$length < 132 ||
			$length > strlen($data) ||
			substr($data, 36, 4) !== 'acsp'
		) {
			return '';
		}

		return substr($data, 0, $length);
	}

	/**
	 * Size as stored and orientation
	 *
	 * @param resource $handle
	 */
	abstract public static function read($handle): Header;

	/**
	 * Moves the handle unless it is there,
	 * as a seek drops PHP's read buffer
	 *
	 * @param resource $handle
	 */
	public static function seek($handle, int $position): void
	{
		if ((int)ftell($handle) !== $position) {
			fseek($handle, $position);
		}
	}

	/**
	 * EXIF data in TIFF's layout from the next `$length` bytes,
	 * without JPEG's marker; with `$offset`, the bytes start
	 * with the offset of the TIFF header after them
	 *
	 * @param resource $handle
	 */
	public static function tiff(
		$handle,
		int $length,
		bool $offset = false
	): string {
		if ($offset === true) {
			$data    = self::bytes($handle, 4);
			$skip    = self::int($data);
			$length -= 4 + $skip;

			self::seek($handle, (int)ftell($handle) + $skip);
		}

		if (Exif::marker($handle) === true) {
			$length -= 6;
		}

		return self::bytes($handle, $length);
	}

	/**
	 * Type and data length of each chunk or box
	 * in the next `$length` bytes, at most `STEPS`
	 *
	 * @param resource $handle
	 * @param 'box'|'png'|'riff' $layout
	 * @return Generator<string, int>
	 */
	public static function walk(
		$handle,
		string $layout,
		int $length = PHP_INT_MAX
	): Generator {
		$position = (int)ftell($handle);
		$end      = $position + min($length, PHP_INT_MAX - $position);

		for ($step = 0; $step < self::STEPS; $step++) {
			$head = (string)fread($handle, 8);

			if (strlen($head) < 8) {
				return;
			}

			/** @var array{size: int, type: string} $fields */
			$fields = unpack($layout === 'riff' ? 'a4type/Vsize' : 'Nsize/a4type', $head);
			$size   = $fields['size'];

			// a box's size counts its header;
			// 0 runs to the end, 1 is followed by a 64-bit size
			if ($layout === 'box') {
				$size = match ($size) {
					0       => $end - (int)ftell($handle),
					1       => self::int((string)fread($handle, 8)) - 16,
					default => $size - 8
				};
			}

			$start = (int)ftell($handle);

			// beyond the enclosing box, or too large for an integer
			if ($size < 0 || $size > $end - $start) {
				return;
			}

			// PNG chunks end with a checksum,
			// RIFF chunks are padded to an even length
			$next = $start + $size + match ($layout) {
				'box'  => 0,
				'png'  => 4,
				'riff' => $size % 2
			};

			yield $fields['type'] => $size;

			// nothing fits after it, as after a box that runs to the end
			if ($end - $next < 8) {
				return;
			}

			self::seek($handle, $next);
		}
	}
}
