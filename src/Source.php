<?php

namespace Darkroom;

use Darkroom\Exception\UnsupportedException;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Reader;
use Darkroom\Tools\Hdr;

/**
 * An image's facts, read lazily from its header
 */
final class Source
{
	use HasSize;

	protected Rect|false|null $canvas = null;

	/**
	 * @var array{int, int, int, int}|null
	 */
	protected array|null $cicp = null;
	protected array|null $exif = null;
	protected Header|null $header = null;
	protected string|null $icc = null;
	protected bool|null $isAnimated = null;
	protected Size|null $size = null;

	public function __construct(
		public readonly string $file
	) {
	}

	public function canvas(): Rect|null
	{
		$this->canvas ??= Reader::open(
			$this->file,
			fn (string $reader, $handle) => $reader::canvas($handle)
		) ?? false;

		return $this->canvas === false ? null : $this->canvas;
	}

	/**
	 * The color signal:
	 * primaries, transfer, matrix and full range (H.273)
	 *
	 * @internal
	 * @return array{int, int, int, int}
	 */
	public function cicp(): array
	{
		return $this->cicp ??= Reader::open(
			$this->file,
			fn (string $reader, $handle) => $reader::cicp($handle)
		) ?? Reader::UNSPECIFIED;
	}

	/**
	 * The raw tags by section (`IFD0`, `EXIF`, `GPS`, …)
	 */
	public function exif(): array
	{
		if ($this->exif !== null) {
			return $this->exif;
		}

		$data = Reader::open(
			$this->file,
			fn (string $reader, $handle) => $reader::exif($handle)
		);

		return $this->exif = Exif::tags($data ?? '');
	}

	/**
	 * @throws UnsupportedException If the file is no image or can't be read
	 */
	public function format(): Format
	{
		return $this->header()->format ?? throw new UnsupportedException(
			message: 'Unsupported or unreadable image: ' . $this->file
		);
	}

	protected function header(): Header
	{
		if ($this->header !== null) {
			return $this->header;
		}

		$header = Reader::open(
			$this->file,
			function (
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
		);

		if ($header !== null) {
			return $this->header = $header;
		}

		// PHP knows the size of more images, such as BMP or TIFF
		$size = $this->file === '' ? false : @getimagesize($this->file);

		if ($size === false) {
			return $this->header = new Header();
		}

		return $this->header = new Header(
			size: new Size($size[0], $size[1])
		);
	}

	public function icc(): string
	{
		return $this->icc ??= Reader::open(
			$this->file,
			fn (string $reader, $handle) => $reader::icc($handle)
		) ?? '';
	}

	public function isAnimated(): bool
	{
		return $this->isAnimated ??= Reader::open(
			$this->file,
			fn (string $reader, $handle) => $reader::isAnimated($handle)
		) === true;
	}

	public function isHdr(): bool
	{
		$transfer = $this->cicp()[1];

		return $transfer === Hdr::PQ || $transfer === Hdr::HLG;
	}

	public function orientation(): Orientation
	{
		return $this->header()->orientation;
	}

	public function size(): Size
	{
		if ($this->size !== null) {
			return $this->size;
		}

		$header = $this->header();

		if (
			$header->size->width === 0 ||
			$header->size->height === 0
		) {
			return $this->size = new Size(0, 0);
		}

		return $this->size = $header->orientation->apply($header->size);
	}
}
