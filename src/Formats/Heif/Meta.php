<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Heif;

use Darkroom\Containers\Bytes;
use Darkroom\Containers\FullBox;
use Darkroom\Containers\Layout;
use Generator;

/**
 * The boxes in the `meta` box, by the first of each type: `pitm` names
 * the primary item, `iinf` each item's type, `iref` which item describes
 * which, `iloc` where each item's data is, `iprp` the properties and
 * which item has which, `idat` the data of some items
 *
 * @internal
 */
final readonly class Meta
{
	/**
	 * @param resource $handle
	 * @param array<string, array{int, int}> $boxes Offset and length of the data by type
	 */
	protected function __construct(
		public mixed $handle,
		public array $boxes
	) {
	}

	/**
	 * The data of the box as a full box, at most `$length` bytes; empty without one
	 */
	public function box(string $type, int $length = Bytes::LIMIT): FullBox
	{
		$box = $this->boxes[$type] ?? null;

		if ($box === null) {
			return new FullBox('');
		}

		Bytes::seek($this->handle, $box[0]);

		$data = Bytes::bytes($this->handle, min($box[1], $length));

		return new FullBox($data);
	}

	/**
	 * Type and data length of each box in the box,
	 * after `$skip` bytes of its own fields; none without one
	 *
	 * @return Generator<string, int>
	 */
	public function boxes(string $type, int $skip = 0): Generator
	{
		$box = $this->boxes[$type] ?? null;

		if ($box === null) {
			return;
		}

		Bytes::seek($this->handle, $box[0] + $skip);

		yield from Layout::Box->walk($this->handle, $box[1] - $skip);
	}

	/**
	 * @param resource $handle
	 */
	public static function index($handle): static
	{
		$boxes = [];

		foreach (self::walk($handle) as $type => $length) {
			$boxes[$type] ??= [(int)ftell($handle), $length];
		}

		return new static($handle, $boxes);
	}

	/**
	 * Type and data length of each box in the `meta` box,
	 * after its version and flags
	 *
	 * @param resource $handle
	 * @return Generator<string, int>
	 */
	public static function walk($handle): Generator
	{
		rewind($handle);

		$boxes  = Layout::Box->walk($handle);
		$length = Layout::find($boxes, 'meta');

		if ($length < 4) {
			return;
		}

		fseek($handle, 4, SEEK_CUR);

		yield from Layout::Box->walk($handle, $length - 4);
	}
}
