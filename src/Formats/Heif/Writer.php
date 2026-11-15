<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Heif;

use Darkroom\Containers\Layout;
use Darkroom\Formats\Writer as Base;
use Darkroom\Meta\Blocks;

/**
 * @internal
 */
final class Writer extends Base
{
	/**
	 * What overwrites the data of each type of meta item, then
	 * what pads it: EXIF data without tags after its offset of 0,
	 * an empty XMP packet, nothing
	 */
	protected const array BLANKS = [
		'Exif' => ["\0\0\0\0MM\0*\0\0\0\x08\0\0\0\0\0\0", "\0"],
		'mime' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/"/>', ' '],
		'uri ' => ['', "\0"],
	];

	/**
	 * The top-level boxes of the image
	 */
	protected const array KEEP = ['ftyp', 'mdat', 'meta', 'moov'];

	/**
	 * Overwrites `$length` bytes at `$offset` with `$data`,
	 * or with nothing where it doesn't fit, then with `$pad`
	 *
	 * @param resource $out
	 */
	protected static function blank(
		$out,
		int $offset,
		int $length,
		string $data,
		string $pad
	): void {
		if ($length < 1) {
			return;
		}

		if (strlen($data) > $length) {
			$data = '';
		}

		fseek($out, $offset);
		fwrite($out, $data);

		for ($left = $length - strlen($data); $left > 0; $left -= 8192) {
			fwrite($out, str_repeat($pad, min($left, 8192)));
		}
	}

	/**
	 * Copies up to the end of the last top-level box in `KEEP`, others
	 * before it as `free` boxes of zeros; overwrites the meta items
	 * in place with `BLANKS`, as offsets point past them, the image's own
	 * EXIF item with `$exif`, but not those of auxiliary images only; false,
	 * without copying, where it doesn't fit; orientation and color profile
	 * are properties
	 *
	 * @param resource $out
	 */
	protected static function clean(
		Meta $meta,
		$out,
		string $exif = ''
	): bool {
		$handle  = $meta->handle;
		$types   = Items::types($meta);
		$items   = array_intersect($types, array_keys(self::BLANKS));
		$kept    = Items::auxiliary($meta, $items);
		$items   = array_diff_key($items, array_flip($kept));
		$iloc    = Iloc::from($meta);
		$extents = $iloc->extents($items);
		$own     = $exif === '' ? null : Items::describing($meta, 'Exif');
		$blanks  = [];
		$last    = 0;

		// in one extent of the image's own item
		if ($exif !== '') {
			$room = $own === null ? [] : $extents[$own] ?? [];

			if (count($room) !== 1 || strlen($exif) > $room[0][1]) {
				return false;
			}
		}

		// the data in the first extent, the padding in all
		foreach ($extents as $item => $list) {
			[$data, $pad] = self::BLANKS[$items[$item]];
			$data         = $item === $own ? $exif : $data;

			foreach ($list as [$offset, $length]) {
				$blanks[] = [$offset, $length, $data, $pad];
				$data     = '';
			}
		}

		rewind($handle);

		foreach (Layout::Box->spans($handle) as $type => [$start, $end]) {
			if (in_array($type, self::KEEP, true) === true) {
				$last = $end;
				continue;
			}

			// the type after the size, the data after the header
			$from     = (int)ftell($handle);
			$blanks[] = [$start + 4, 4, 'free', ''];
			$blanks[] = [$from, $end - $from, '', "\0"];
		}

		rewind($handle);

		$copied = (int)stream_copy_to_stream($handle, $out, $last);

		foreach ($blanks as [$offset, $length, $data, $pad]) {
			self::blank($out, $offset, min($length, $copied - $offset), $data, $pad);
		}

		return true;
	}

	/**
	 * Copies the image without meta, but with the blocks'
	 * EXIF data in its own item where it fits, else without
	 *
	 * @param resource $handle
	 * @param resource $out
	 */
	public static function write(
		$handle,
		$out,
		Blocks $blocks
	): void {
		$exif = $blocks->exif === '' ? '' : pack('N', 0) . $blocks->exif;

		rewind($handle);

		$meta = Meta::index($handle);

		if (self::clean($meta, $out, $exif) === false) {
			self::clean($meta, $out);
		}
	}
}
