<?php

declare(strict_types = 1);

namespace Darkroom\Readers;

use Darkroom\Orientation;
use Darkroom\Rect;
use Darkroom\Size;
use Generator;

/**
 * HEIF files (HEIC, AVIF) describe their images
 * as items with properties in the `meta` box
 *
 * @internal
 */
final class Heif extends Reader
{
	/**
	 * By a horizontal flip or none, then by clockwise quarter turns
	 */
	protected const array ORIENTATIONS = [
		[
			Orientation::None,
			Orientation::Rotate90,
			Orientation::Rotate180,
			Orientation::Rotate270
		],
		[
			Orientation::FlipHorizontal,
			Orientation::Transverse,
			Orientation::FlipVertical,
			Orientation::Transpose
		]
	];

	/**
	 * The bytes parsed of each property
	 */
	protected const array PROPERTIES = [
		'clap' => 32,
		'colr' => 11,
		'imir' => 1,
		'irot' => 1,
		'ispe' => 12,
	];

	/**
	 * The coded size and the clean aperture within it
	 *
	 * @param list<array{string, string}> $properties
	 * @return array{Size, Rect}
	 */
	protected static function aperture(array $properties): array
	{
		$ispe = '';
		$clap = '';

		foreach ($properties as [$type, $data]) {
			match ($type) {
				'clap'  => $clap = $data,
				'ispe'  => $ispe = $data,
				default => null
			};
		}

		// after version and flags
		$image = new Size(
			self::int(substr($ispe, 4, 4)),
			self::int(substr($ispe, 8, 4))
		);

		if ($clap === '') {
			return [$image, new Rect(0, 0, $image->width, $image->height)];
		}

		[$left, $right] = self::span($clap, $image->width, horizontal: true);
		[$top, $bottom] = self::span($clap, $image->height, horizontal: false);

		$aperture = new Rect(
			x:      $left,
			y:      $top,
			width:  max(0, $right - $left + 1),
			height: max(0, $bottom - $top + 1)
		);

		return [$image, $aperture];
	}

	/**
	 * The item's property indexes from an `ipma` box
	 *
	 * @param resource $handle
	 * @return list<int>|null
	 */
	protected static function associations(
		$handle,
		int $length,
		int $item
	): array|null {
		$data     = self::bytes($handle, $length);
		$position = 4;
		$entries  = self::take($data, $position, 4);

		// version 1 with 32-bit IDs;
		// flag 1 with 16-bit indexes, else 8-bit ones
		$id     = ($data[0] ?? "\0") === "\0" ? 2 : 4;
		$size   = (ord($data[3] ?? "\0") & 1) === 1 ? 2 : 1;
		$format = $id === 2 ? 'nid/Ccount' : 'Nid/Ccount';

		// sorted by ID: ID, number of indexes, indexes
		for (
			$entry = 0;
			$entry < $entries && $position + $id < strlen($data);
			$entry++
		) {
			/** @var array{id: int, count: int} $fields */
			$fields    = unpack($format, $data, $position);
			$found     = $fields['id'];
			$count     = $fields['count'];
			$position += $id + 1;

			if ($found > $item) {
				return null;
			}

			if ($found === $item) {
				$indexes = [];

				// without the top bit, which marks essential properties
				for ($index = 0; $index < $count; $index++) {
					$indexes[] = self::take($data, $position, $size) & ($size === 2 ? 0x7FFF : 0x7F);
				}

				return $indexes;
			}

			$position += $count * $size;
		}

		return null;
	}

	/**
	 * The clean aperture within the coded image,
	 * which some decoders return whole
	 *
	 * @param resource $handle
	 */
	public static function canvas($handle): Rect|null
	{
		$properties         = self::properties($handle, ['clap', 'ispe']);
		[$image, $aperture] = self::aperture($properties);

		if ($aperture == new Rect(0, 0, $image->width, $image->height)) {
			return null;
		}

		return $aperture;
	}

	/**
	 * The color signal in the primary item's `nclx` color property
	 *
	 * @param resource $handle
	 * @return array{int, int, int, int}
	 */
	public static function cicp($handle): array
	{
		$properties = self::properties($handle, ['colr']);

		// the color type, three 16-bit codes,
		// then the range flag in the top bit
		foreach ($properties as [, $data]) {
			if (str_starts_with($data, 'nclx') === true && strlen($data) >= 11) {
				/** @var array{code1: int, code2: int, code3: int, range: int} $codes */
				$codes = unpack('n3code/Crange', $data, 4);

				return [
					$codes['code1'],
					$codes['code2'],
					$codes['code3'],
					$codes['range'] >> 7
				];
			}
		}

		return self::UNSPECIFIED;
	}

	/**
	 * Whether the list holds the entry, at a multiple of its length
	 */
	protected static function contains(string $list, string $entry): bool
	{
		$offset = strpos($list, $entry);

		while ($offset !== false && $offset % strlen($entry) !== 0) {
			$offset = strpos($list, $entry, $offset + 1);
		}

		return $offset !== false;
	}

	/**
	 * The `Exif` item that describes the primary item
	 *
	 * @param resource $handle
	 */
	public static function exif($handle): string
	{
		$primary = 0;
		$items   = [];
		$iloc    = null;
		$iref    = null;

		// `iloc` and `iref` once the items are known
		foreach (self::meta($handle) as $type => $length) {
			match ($type) {
				'iinf'  => $items   = self::items($handle, $length),
				'iloc'  => $iloc    = [(int)ftell($handle), $length],
				'iref'  => $iref    = [(int)ftell($handle), $length],
				'pitm'  => $primary = self::primary($handle, $length),
				default => null
			};
		}

		if ($iloc === null || $iref === null) {
			return '';
		}

		self::seek($handle, $iref[0]);

		$item = self::reference($handle, $iref[1], $items, $primary);

		if ($item === null) {
			return '';
		}

		self::seek($handle, $iloc[0]);

		$data     = self::bytes($handle, $iloc[1]);
		$location = self::location($data, $item);

		if ($location === null) {
			return '';
		}

		self::seek($handle, $location[0]);

		return self::tiff($handle, $location[1], offset: true);
	}

	/**
	 * The IDs of the `Exif` items in an `iinf` box
	 *
	 * @param resource $handle
	 * @return list<int>
	 */
	protected static function items($handle, int $length): array
	{
		// version and flags, then a 16-bit number of entries,
		// or a 32-bit one from version 1
		$head  = self::bytes($handle, 4);
		$skip  = ($head[0] ?? "\0") === "\0" ? 2 : 4;
		$items = [];

		fseek($handle, $skip, SEEK_CUR);

		foreach (self::walk($handle, 'box', $length - 4 - $skip) as $type => $size) {
			$data = $type === 'infe' ? self::bytes($handle, min($size, 14)) : '';

			// version 2 with 16-bit IDs, 3 with 32-bit ones;
			// then the protection index and the item type
			$id = match (ord($data[0] ?? "\0")) {
				2       => 2,
				3       => 4,
				default => 0
			};

			if ($id > 0 && substr($data, $id + 6, 4) === 'Exif') {
				$items[] = self::int(substr($data, 4, $id));
			}
		}

		return $items;
	}

	/**
	 * Offset and length of the item's first extent
	 * in the file, from an `iloc` box
	 *
	 * @return array{int, int}|null
	 */
	protected static function location(string $data, int $item): array|null
	{
		$version  = ord($data[0] ?? "\0");
		$position = 4;

		// sizes of offsets, lengths, base offsets and extent indexes
		$sizes  = self::take($data, $position, 2);
		$offset = $sizes >> 12;
		$length = $sizes >> 8 & 15;
		$base   = $sizes >> 4 & 15;
		$index  = $version > 0 ? $sizes & 15 : 0;

		// 32-bit IDs from version 2, a construction method from version 1
		$id      = $version < 2 ? 2 : 4;
		$method  = $version > 0 ? 2 : 0;
		$entries = self::take($data, $position, $id);

		// per item: ID, construction method, data reference,
		// base offset, number of extents, extents
		$head = $id + $method + 2 + $base;

		for (
			$entry = 0;
			$entry < $entries && $position < strlen($data);
			$entry++
		) {
			$found   = self::int(substr($data, $position, $id));
			$extents = self::int(substr($data, $position + $head, 2));

			if ($found === $item) {
				$position += $id;
				$stored    = self::take($data, $position, $method) & 15;
				$position += 2;
				$start     = self::take($data, $position, $base);
				$position += 2 + $index;
				$start    += self::take($data, $position, $offset);
				$size      = self::take($data, $position, $length);

				// in the file, not in the `idat` box
				if (
					$stored !== 0 ||
					$start < 0 ||
					is_int($start) === false
				) {
					return null;
				}

				return [$start, $size];
			}

			$position += $head + 2 + $extents * ($index + $offset + $length);
		}

		return null;
	}

	/**
	 * Type and data length of each box in the `meta` box
	 *
	 * @param resource $handle
	 * @return Generator<string, int>
	 */
	protected static function meta($handle): Generator
	{
		$boxes  = self::walk($handle, 'box');
		$length = self::find($boxes, 'meta');

		if ($length > 0) {
			// after its version and flags
			fseek($handle, 4, SEEK_CUR);

			yield from self::walk($handle, 'box', $length - 4);
		}
	}

	/**
	 * The item ID from a `pitm` box
	 *
	 * @param resource $handle
	 */
	protected static function primary($handle, int $length): int
	{
		// version and flags, then a 16-bit ID,
		// or a 32-bit one from version 1
		$data = self::bytes($handle, min($length, 8));
		$id   = ($data[0] ?? "\0") === "\0" ? 2 : 4;

		return self::int(substr($data, 4, $id));
	}

	/**
	 * Type and data of the primary item's properties
	 * of the types, in the order they apply
	 *
	 * @param resource $handle
	 * @param list<key-of<self::PROPERTIES>> $types
	 * @return list<array{string, string}>
	 */
	protected static function properties($handle, array $types): array
	{
		$primary = 0;
		$iprp    = null;
		$ipco    = null;
		$indexes = null;

		// `iprp` once the primary item is known, usually right away
		foreach (self::meta($handle) as $type => $length) {
			match ($type) {
				'iprp'  => $iprp    = [(int)ftell($handle), $length],
				'pitm'  => $primary = self::primary($handle, $length),
				default => null
			};

			if ($iprp !== null && $primary > 0) {
				break;
			}
		}

		if ($iprp === null) {
			return [];
		}

		self::seek($handle, $iprp[0]);

		$boxes = 0;

		// the first `ipco`, and the first `ipma`
		// that lists the item, of at most four
		foreach (self::walk($handle, 'box', $iprp[1]) as $type => $length) {
			if ($type === 'ipma' && ++$boxes > 4) {
				continue;
			}

			match ($type) {
				'ipco'  => $ipco    ??= [(int)ftell($handle), $length],
				'ipma'  => $indexes ??= self::associations($handle, $length, $primary),
				default => null
			};
		}

		if ($ipco === null || $indexes === null) {
			return [];
		}

		self::seek($handle, $ipco[0]);

		$found = [];
		$index = 0;

		// counted from 1; only the bytes that are parsed
		foreach (self::walk($handle, 'box', $ipco[1]) as $type => $length) {
			$index++;

			if (
				in_array($type, $types, true) === true &&
				in_array($index, $indexes, true) === true
			) {
				$found[$index] = [
					$type,
					self::bytes($handle, min($length, self::PROPERTIES[$type]))
				];
			}
		}

		$properties = array_map(
			fn (int $index) => $found[$index] ?? null,
			$indexes
		);

		return array_values(array_filter($properties));
	}

	/**
	 * Size and orientation from the primary item's properties,
	 * which EXIF doesn't override
	 *
	 * @param resource $handle
	 */
	public static function read($handle): Header
	{
		$properties   = self::properties($handle, ['clap', 'imir', 'irot', 'ispe']);
		[, $aperture] = self::aperture($properties);
		$size         = new Size($aperture->width, $aperture->height);
		$flip         = 0;
		$turns        = 0;

		foreach ($properties as [$type, $data]) {
			match ($type) {
				// anticlockwise quarter turns
				'irot'  => $turns -= self::int($data) & 3,
				// mirroring (0: top and bottom, 1: left and right)
				// after the turns equals a flip before them,
				// the turns reversed, plus two for 0
				'imir'  => [$flip, $turns] = [1 - $flip, ((self::int($data) & 1) === 1 ? 0 : 2) - $turns],
				default => null
			};
		}

		$orientation = self::ORIENTATIONS[$flip][$turns & 3];

		return new Header($size, $orientation);
	}

	/**
	 * The first of the items with a content description
	 * reference to the item, from an `iref` box
	 *
	 * @param resource $handle
	 * @param list<int> $items
	 */
	protected static function reference(
		$handle,
		int $length,
		array $items,
		int $item
	): int|null {
		// version 1 with 32-bit IDs
		$head  = self::bytes($handle, 4);
		$id    = ($head[0] ?? "\0") === "\0" ? 2 : 4;
		$entry = pack($id === 2 ? 'n' : 'N', $item);

		foreach (self::walk($handle, 'box', $length - 4) as $type => $size) {
			if ($type !== 'cdsc') {
				continue;
			}

			// from, number of references, to
			$data  = self::bytes($handle, $id + 2);
			$from  = self::int(substr($data, 0, $id));
			$count = self::int(substr($data, $id, 2));

			if (in_array($from, $items, true) === true) {
				$targets = self::bytes(
					$handle,
					min($count * $id, $size - $id - 2)
				);

				if (self::contains($targets, $entry) === true) {
					return $from;
				}
			}
		}

		return null;
	}

	/**
	 * First and last pixel of the clean aperture on one axis,
	 * clamped to the image; rounded as libheif does:
	 * horizontally down, vertically to the nearest
	 *
	 * @return array{int, int}
	 */
	protected static function span(
		string $clap,
		int $image,
		bool $horizontal
	): array {
		// width or height a / b,
		// the centre's offset c / d:
		// signed numerators, denominators ≥ 1
		$axis   = $horizontal === true ? 0 : 8;
		$length = self::int(substr($clap, $axis, 8));
		$offset = self::int(substr($clap, 16 + $axis, 8));
		$a      = $length >> 32;
		$b      = max(1, $length & 0xFFFFFFFF);
		$c      = $offset >> 32;
		$d      = max(1, $offset & 0xFFFFFFFF);

		// in one division, so that halves and thirds stay exact
		$start = fdiv(2 * $c * $b + ($image * $b - $a) * $d, 2 * $d * $b);
		$first = (int)($horizontal === true ? $start : $start + 0.5);
		$last  = $first + (int)floor(fdiv($a, $b) - 0.5);

		return [max(0, $first), min($image - 1, $last)];
	}

	/**
	 * The integer of `$length` bytes at `$position`
	 */
	protected static function take(
		string $data,
		int &$position,
		int $length
	): int {
		$value     = self::int(substr($data, $position, $length));
		$position += $length;

		return $value;
	}
}
