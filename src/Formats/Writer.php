<?php

declare(strict_types = 1);

namespace Darkroom\Formats;

use Darkroom\Format;
use Darkroom\Meta\Blocks;

/**
 * Copies a container format without meta, but with
 * what it keeps: the color profile, the orientation, blocks
 *
 * @internal
 */
abstract class Writer
{
	/**
	 * Whether the format keeps the orientation in its EXIF data
	 *
	 * @var bool
	 */
	public const bool ORIENTS = false;

	/**
	 * The encoded image with the blocks
	 */
	public static function embed(
		string $data,
		Format $format,
		Blocks $blocks
	): string {
		$writer = self::for($format);
		$in     = fopen('php://memory', 'w+b');
		$out    = fopen('php://memory', 'w+b');

		if ($in === false || $out === false) {
			return $data;
		}

		fwrite($in, $data);
		rewind($in);

		$writer::write($in, $out, $blocks);

		rewind($out);

		return (string)stream_get_contents($out);
	}

	/**
	 * @return class-string<Writer>
	 */
	public static function for(Format $format): string
	{
		return match ($format) {
			Format::Avif,
			Format::Heic => Heif\Writer::class,
			Format::Gif => Gif\Writer::class,
			Format::Jpeg => Jpeg\Writer::class,
			Format::Jxl => Jxl\Writer::class,
			Format::Png => Png\Writer::class,
			Format::Webp => Webp\Writer::class,
		};
	}

	/**
	 * Copies the image from the start of `$handle` to `$out` without
	 * meta, but with its color profile and orientation unless the
	 * blocks have them, and with the blocks where the format has a place
	 *
	 * @param resource $handle
	 * @param resource $out
	 */
	abstract public static function write(
		$handle,
		$out,
		Blocks $blocks
	): void;
}
