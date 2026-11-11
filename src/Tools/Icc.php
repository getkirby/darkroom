<?php

declare(strict_types = 1);

namespace Darkroom\Tools;

use Darkroom\Format;
use Darkroom\Readers\Reader;
use Darkroom\Readers\Webp;

/**
 * Embeds an ICC profile into an encoded image
 *
 * @internal
 */
final class Icc
{
	/**
	 * The most bytes of the profile in one JPEG segment
	 */
	protected const int SEGMENT = 65519;

	/**
	 * The image with the profile in JPEG, PNG and WebP
	 */
	public static function embed(
		string $data,
		Format $format,
		string $profile
	): string {
		if ($profile === '') {
			return $data;
		}

		return match ($format) {
			Format::Jpeg => static::jpeg($data, $profile),
			Format::Png  => static::png($data, $profile),
			Format::Webp => static::webp($data, $profile),
			default      => $data
		};
	}

	/**
	 * `ICC_PROFILE` APP2 segments after the start of image
	 * and a JFIF segment, which come first
	 */
	protected static function jpeg(string $data, string $profile): string
	{
		$parts    = str_split($profile, self::SEGMENT);
		$count    = chr(count($parts));
		$segments = '';
		$offset   = 2;

		// the length counts itself; the sequence number counts from 1
		foreach ($parts as $index => $part) {
			$length    = pack('n', 16 + strlen($part));
			$segments .= "\xFF\xE2" . $length . "ICC_PROFILE\0" . chr($index + 1) . $count . $part;
		}

		if (substr($data, 2, 2) === "\xFF\xE0") {
			$offset += 2 + Reader::int(substr($data, 4, 2));
		}

		return substr_replace($data, $segments, $offset, 0);
	}

	/**
	 * An `iCCP` chunk after the `IHDR` chunk,
	 * which comes first; none without zlib
	 */
	protected static function png(string $data, string $profile): string
	{
		if (function_exists('gzcompress') === false) {
			return $data;
		}

		// a name, a null byte and the compression method, deflate
		$chunk = 'iCCP' . "icc\0\0" . (string)gzcompress($profile);
		$crc   = crc32($chunk);
		$iccp  = pack('N', strlen($chunk) - 4) . $chunk . pack('N', $crc);

		// after the signature and the 25 bytes of `IHDR`
		return substr_replace($data, $iccp, 33, 0);
	}

	/**
	 * The `VP8X` chunk of a simple file: its canvas,
	 * and the alpha flag of a lossless one with alpha
	 */
	protected static function vp8x(string $chunk): string
	{
		$size   = Webp::size($chunk);
		$width  = substr(pack('V', $size->width - 1), 0, 3);
		$height = substr(pack('V', $size->height - 1), 0, 3);

		// the bit after 14 bits each for width − 1 and height − 1
		$alpha =
			str_starts_with($chunk, 'VP8L') === true &&
			(ord($chunk[12] ?? "\0") & 0x10) === 0x10;

		$flags = $alpha === true ? "\x10" : "\0";

		return 'VP8X' . pack('V', 10) . $flags . "\0\0\0" . $width . $height;
	}

	/**
	 * An `ICCP` chunk after the `VP8X` chunk, with its flag;
	 * a simple file is extended to have one
	 */
	protected static function webp(string $data, string $profile): string
	{
		$first    = substr($data, 12, 18);
		$extended = str_starts_with($first, 'VP8X');
		$vp8x     = $extended === true ? $first : static::vp8x($first);
		$rest     = substr($data, $extended === true ? 30 : 12);
		$pad      = str_repeat("\0", strlen($profile) % 2);
		$iccp     = 'ICCP' . pack('V', strlen($profile)) . $profile . $pad;

		$vp8x[8] = chr(ord($vp8x[8]) | 0x20);

		$webp = 'WEBP' . $vp8x . $iccp . $rest;

		return 'RIFF' . pack('V', strlen($webp)) . $webp;
	}
}
