<?php

namespace Darkroom\Readers;

use Darkroom\Orientation;

/**
 * EXIF data, which has TIFF's layout in every container
 *
 * @internal
 */
final class Exif
{
	/**
	 * Moves past JPEG's `Exif\0\0` marker,
	 * if the data at the handle starts with it
	 *
	 * @param resource $handle
	 */
	public static function marker($handle): bool
	{
		$start = (int)ftell($handle);

		if (fread($handle, 6) === "Exif\0\0") {
			return true;
		}

		Reader::seek($handle, $start);

		return false;
	}

	/**
	 * The orientation from the first IFD
	 * of the data at the handle
	 *
	 * @param resource $handle
	 */
	public static function orientation($handle): Orientation
	{
		$start       = (int)ftell($handle);
		$head        = (string)fread($handle, 8);
		$orientation = 1;

		// the byte order, little-endian or big-endian
		$little = match (substr($head, 0, 4)) {
			"II*\0" => true,
			"MM\0*" => false,
			default => null
		};

		if ($little !== null) {
			// counted from the start of the TIFF header
			$offset = Reader::int(substr($head, 4), $little);
			Reader::seek($handle, $start + $offset);

			// the number of entries, then 12 bytes each;
			// one cut off is left out
			$count   = Reader::int((string)fread($handle, 2), $little);
			$entries = Reader::bytes($handle, min($count, Reader::STEPS) * 12);
			$format  = $little === true ? 'vtag/vtype/x4/Vvalue' : 'ntag/ntype/x4/Nvalue';

			for (
				$position = 0;
				$position + 12 <= strlen($entries);
				$position += 12
			) {
				/** @var array{tag: int, type: int, value: int} $entry */
				$entry = unpack($format, $entries, $position);
				$value = $entry['value'];

				if ($entry['tag'] !== 0x0112) {
					continue;
				}

				// a SHORT value fills the first two bytes, a LONG one all four
				$orientation = match ($entry['type']) {
					3       => $little === true ? $value & 0xFFFF : $value >> 16,
					4       => $value,
					default => $orientation
				};
			}
		}

		return Orientation::tryFrom($orientation) ?? Orientation::None;
	}

	/**
	 * All tags by section, through ext-exif
	 */
	public static function tags(string $data): array
	{
		if (
			$data === '' ||
			function_exists('exif_read_data') === false
		) {
			return [];
		}

		// a memory stream, as `data:` URLs need `allow_url_fopen`
		/** @var resource $stream */
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $data);
		rewind($stream);

		$tags = @exif_read_data($stream, as_arrays: true);
		fclose($stream);

		if (is_array($tags) === false) {
			return [];
		}

		// what ext-exif adds about the stream itself
		unset($tags['FILE'], $tags['COMPUTED']);

		return $tags;
	}
}
