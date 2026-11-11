<?php

namespace Darkroom\Readers;

use Darkroom\Orientation;
use Darkroom\Size;
use Generator;

/**
 * @internal
 */
final class Jpeg extends Reader
{
	/**
	 * The EXIF block of the first `Exif` APP1 segment
	 *
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		foreach (self::segments($handle) as $marker => $length) {
			if ($marker === 0xE1 && Exif::marker($handle) === true) {
				return self::bytes($handle, $length - 6);
			}
		}

		return '';
	}

	/**
	 * The profile of the `ICC_PROFILE` APP2 segments,
	 * joined by their sequence numbers
	 *
	 * @param resource $handle
	 */
	public static function icc($handle): string
	{
		$chunks = [];
		$counts = [];
		$total  = 0;

		foreach (self::segments($handle) as $marker => $length) {
			if ($marker !== 0xE2 || $length < 14) {
				continue;
			}

			$head = self::bytes($handle, 14);

			if (str_starts_with($head, "ICC_PROFILE\0") === false) {
				continue;
			}

			// the sequence number from 1, then the number of segments
			$number   = ord($head[12] ?? "\0");
			$counts[] = ord($head[13] ?? "\0");
			$total   += $length - 14;

			if (isset($chunks[$number]) === true || $total > self::LIMIT) {
				return '';
			}

			$chunks[$number] = self::bytes($handle, $length - 14);
		}

		ksort($chunks);

		$count = count($chunks);

		// numbered from 1 to the number each segment states
		if (
			$count === 0 ||
			array_keys($chunks) !== range(1, $count) ||
			array_diff($counts, [$count]) !== []
		) {
			return '';
		}

		$profile = implode('', $chunks);

		return self::profile($profile);
	}

	/**
	 * The next marker, with the handle after it,
	 * past fill bytes, at most about `LIMIT` in all
	 *
	 * @param resource $handle
	 */
	protected static function marker($handle, int &$fills): int|null
	{
		if (fread($handle, 1) !== "\xFF") {
			return null;
		}

		// more 0xFF are fill bytes; 0x00 after one is no marker
		for (; $fills < self::LIMIT; $fills++) {
			$byte = (string)fread($handle, 1);

			if ($byte !== "\xFF") {
				return $byte === '' || $byte === "\0" ? null : ord($byte);
			}
		}

		return null;
	}

	/**
	 * Size from the first frame header,
	 * orientation from the first `Exif` APP1 segment
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$size        = null;
		$orientation = null;

		foreach (self::segments($handle) as $marker => $length) {
			if (
				$marker === 0xE1 &&
				$orientation === null &&
				Exif::marker($handle) === true
			) {
				$orientation = Exif::orientation($handle);
			}

			// SOF0–SOF15 except DHT, JPG and DAC: precision, height, width
			if (
				$size === null &&
				$marker >= 0xC0 &&
				$marker <= 0xCF &&
				in_array($marker, [0xC4, 0xC8, 0xCC], true) === false
			) {
				$frame = self::bytes($handle, min($length, 5));
				$size  = new Size(0, 0);

				// not from a frame header that is cut off
				if (strlen($frame) === 5) {
					$width  = self::int(substr($frame, 3, 2));
					$height = self::int(substr($frame, 1, 2));
					$size   = new Size($width, $height);
				}
			}

			if ($size !== null && $orientation !== null) {
				break;
			}
		}

		return new Header(
			$size ?? new Size(0, 0),
			$orientation ?? Orientation::None
		);
	}

	/**
	 * Marker and data length of each segment before the scan, at most `STEPS`;
	 * the caller may read the data, the walk goes on after it
	 *
	 * @param resource $handle
	 * @return Generator<int, int>
	 */
	protected static function segments($handle): Generator
	{
		if (fread($handle, 2) !== "\xFF\xD8") {
			return;
		}

		$fills = 0;

		for ($step = 0; $step < self::STEPS; $step++) {
			$marker = self::marker($handle, $fills);

			// none, start of scan, end of image
			if (
				$marker === null ||
				$marker === 0xDA ||
				$marker === 0xD9
			) {
				return;
			}

			// the length counts its own two bytes
			$bytes  = (string)fread($handle, 2);
			$length = self::int($bytes) - 2;

			if (strlen($bytes) < 2 || $length < 0) {
				return;
			}

			$next = (int)ftell($handle) + $length;

			yield $marker => $length;

			self::seek($handle, $next);
		}
	}
}
