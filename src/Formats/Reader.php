<?php

declare(strict_types = 1);

namespace Darkroom\Formats;

use Darkroom\Containers\Bytes;
use Darkroom\Format;
use Darkroom\Rect;

/**
 * Reads a container format from a file's handle, which it closes
 *
 * @internal
 */
abstract class Reader
{
	/**
	 * The color signal of a file without one, as libheif's:
	 * unspecified primaries, transfer and matrix, full range
	 */
	public const array UNSPECIFIED = [2, 2, 2, 1];

	/**
	 * @param resource $handle
	 */
	final public function __construct(
		protected $handle,
		public readonly Format|null $format = null
	) {
	}

	public function __destruct()
	{
		fclose($this->handle);
	}

	public function canvas(): Rect|null
	{
		return null;
	}

	/**
	 * The color signal: primaries, transfer, matrix and full range (H.273)
	 *
	 * @return array{int, int, int, int}
	 */
	public function cicp(): array
	{
		return self::UNSPECIFIED;
	}

	public function exif(): string
	{
		return '';
	}

	/**
	 * @return class-string<Reader>
	 */
	public static function for(Format $format): string
	{
		return match ($format) {
			Format::Avif,
			Format::Heic => Heif\Reader::class,
			Format::Gif => Gif\Reader::class,
			Format::Jpeg => Jpeg\Reader::class,
			Format::Jxl => Jxl\Reader::class,
			Format::Png => Png\Reader::class,
			Format::Webp => Webp\Reader::class,
		};
	}

	abstract public function header(): Header;

	public function icc(): string
	{
		return '';
	}

	public function isAnimated(): bool
	{
		return false;
	}

	/**
	 * The reader of the file's format; none for others
	 */
	public static function open(string $file): self|null
	{
		// fopen() throws for an empty path
		$handle = $file === '' ? false : @fopen($file, 'rb');

		if ($handle === false) {
			return null;
		}

		// a directory opens, but can't be read
		$format = Format::detect((string)@fread($handle, 64));

		if ($format === null) {
			fclose($handle);
			return null;
		}

		$reader = self::for($format);

		return new $reader($handle, $format);
	}

	/**
	 * The profile at the start of the data, without what follows it
	 */
	public static function profile(string $data): string
	{
		$length = Bytes::int(substr($data, 0, 4));

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
}
