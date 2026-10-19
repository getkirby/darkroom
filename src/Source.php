<?php

namespace Darkroom;

use Darkroom\Exception\UnsupportedException;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Reader;
use Darkroom\Tools\Hdr;

/**
 * Source facts, read lazily and at most once from the file headers
 */
final class Source
{
	private bool|null $animated = null;
	private Rect|false|null $canvas = null;

	/**
	 * @var array{int, int, int, int}|null
	 */
	private array|null $cicp = null;
	private array|null $exif = null;
	private Header|null $header = null;
	private string|null $icc = null;
	private Size|null $size = null;

	public function __construct(
		public readonly string $file
	) {
	}

	/**
	 * The canvas within the image a decoder returns
	 * without placing it, in stored orientation
	 */
	public function canvas(): Rect|null
	{
		$this->canvas ??= Reader::open(
			file: $this->file,
			call: fn (string $reader, $handle) => $reader::canvas($handle)
		) ?? false;

		return $this->canvas === false ? null : $this->canvas;
	}

	/**
	 * The color signal:
	 * primaries, transfer, matrix and full range (H.273),
	 * unspecified without one
	 *
	 * @internal
	 * @return array{int, int, int, int}
	 */
	public function cicp(): array
	{
		return $this->cicp ??= Reader::open(
			file: $this->file,
			call: fn (string $reader, $handle) => $reader::cicp($handle)
		) ?? Reader::UNSPECIFIED;
	}

	/**
	 * The raw tags by section (`IFD0`, `EXIF`, `GPS`, …)
	 */
	public function exif(): array
	{
		if ($this->exif === null) {
			$data = Reader::open(
				file: $this->file,
				call: fn (string $reader, $handle) => $reader::exif($handle)
			);

			$this->exif = Exif::tags($data ?? '');
		}

		return $this->exif;
	}

	/**
	 * From the file signature, not the extension
	 *
	 * @throws UnsupportedException If the file is no image or can't be read
	 */
	public function format(): Format
	{
		return $this->header()->format ?? throw new UnsupportedException(
			message: 'Unsupported or unreadable image: ' . $this->file
		);
	}

	/**
	 * Format, size and orientation, from one open
	 */
	private function header(): Header
	{
		return $this->header ??= Reader::open(
			file: $this->file,
			call: function (
				string $reader,
				$handle,
				Format $format
			): Header {
				$header = $reader::read($handle);

				return new Header(
					$header->size,
					$header->orientation,
					$format
				);
			}
		) ?? new Header();
	}

	/**
	 * The ICC color profile of JPEG, PNG and WebP,
	 * `''` without one
	 */
	public function icc(): string
	{
		return $this->icc ??= Reader::open(
			file: $this->file,
			call: fn (string $reader, $handle) => $reader::icc($handle)
		) ?? '';
	}

	/**
	 * Read on its own, as it can take more of the file than the size
	 */
	public function isAnimated(): bool
	{
		return $this->animated ??= Reader::open(
			file: $this->file,
			call: fn (string $reader, $handle) => $reader::isAnimated($handle)
		) === true;
	}

	/**
	 * A PQ or HLG transfer
	 */
	public function isHdr(): bool
	{
		$transfer = $this->cicp()[1];

		return $transfer === Hdr::PQ || $transfer === Hdr::HLG;
	}

	public function orientation(): Orientation
	{
		return $this->header()->orientation;
	}

	/**
	 * As displayed (orientation applied)
	 */
	public function size(): Size
	{
		if ($this->size !== null) {
			return $this->size;
		}

		$header = $this->header();

		if ($header->size->width === 0 || $header->size->height === 0) {
			return $this->size = new Size(0, 0);
		}

		return $this->size = $header->orientation->apply($header->size);
	}
}
