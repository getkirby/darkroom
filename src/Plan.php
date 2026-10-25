<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * The computed plan from the thumbnail options
 * with all information a driver needs to generate it
 */
final readonly class Plan
{
	use HasSize;

	protected function __construct(
		public Source $source,
		public Options $options,
		public Rect $crop,
		protected Size $size,
		public Orientation $orientation,
		public bool $frames,
		public Format $format,
		public int $quality,
		public bool $interlace,
		public bool $icc,
	) {
	}

	public static function for(
		Source $source,
		Options $options,
		Defaults $defaults = new Defaults(),
		Driver|null $driver = null
	): static {
		$input    = $source->format();
		$original = $source->size();
		$focus    = $options->focus();

		if ($options->crop instanceof Rect) {
			$crop = static::part($options->crop, $original);

			if ($options->width === null || $options->height === null) {
				$cut  = new Size($crop->width, $crop->height);
				$size = $cut->fit($options->width, $options->height);
			} else {
				// both sides as asked, shrunk into the part
				$size = new Size($options->width, $options->height);
				$size = $size->fit($crop->width, $crop->height);
			}
		} elseif ($focus === null) {
			$size = $original->fit($options->width, $options->height);
			$crop = new Rect(0, 0, $original->width, $original->height);
		} else {
			$size = new Size(
				$options->width ?? $options->height ?? $original->width,
				$options->height ?? $options->width ?? $original->height
			);
			$cut  = $original->crop($size);
			$crop = $focus->place($cut, $original);
			$size = $size->fit($crop->width, $crop->height);
		}

		$output = $options->format ?? $defaults->format ?? $input;

		return new static(
			source:      $source,
			options:     $options,
			crop:        $crop,
			size:        $size,
			orientation: $driver?->orients($source) === true ? Orientation::None : $source->orientation(),
			frames:      $driver?->canAnimate($output) === true && $source->isAnimated() === true,
			format:      $output,
			quality:     $options->quality ?? $defaults->quality ?? Defaults::QUALITY,
			interlace:   $options->interlace ?? $defaults->interlace ?? Defaults::INTERLACE,
			icc:         $options->icc ?? $defaults->icc ?? Defaults::ICC
		);
	}

	/**
	 * What to ask a JPEG decoder for, so it loads
	 * the smallest M/8 that covers 1.5 × `::scale()`
	 */
	public function jpegSize(): Size
	{
		$size = $this->source->size();
		$size = $this->orientation->apply($size);

		if ($size->pixels() === 0) {
			return $size;
		}

		$scale = $this->scale();

		$eighths = (int)min(8, max(
			ceil(12 * $scale->width / $size->width),
			ceil(12 * $scale->height / $size->height)
		));

		// half an eighth below M/8, so decoders that
		// round and those that cover both load M/8
		return new Size(
			max($scale->width, (int)ceil($size->width * (2 * $eighths - 1) / 16)),
			max($scale->height, (int)ceil($size->height * (2 * $eighths - 1) / 16))
		);
	}

	/**
	 * Names what the plan writes, without the format;
	 * plans that write the same image from a source share it
	 */
	public function key(): string
	{
		$original = $this->source->size();
		$crop     = $this->crop;
		$cut      = sprintf('crop%d-%d-%d-%d', $crop->x, $crop->y, $crop->width, $crop->height);
		$whole    =
			$crop->width === $original->width &&
			$crop->height === $original->height;

		$parts = [
			$this->size->width . 'x' . $this->size->height,
			$whole === true ? null : $cut,
			$this->options->blur !== null ? 'blur' . $this->options->blur : null,
			$this->options->grayscale === true ? 'grayscale' : null,
			$this->options->sharpen !== null ? 'sharpen' . $this->options->sharpen : null,
			'q' . $this->quality,
			$this->interlace === true ? 'interlace' : null,
			$this->icc === false ? 'noicc' : null,
		];

		$parts = array_filter($parts, fn ($part) => $part !== null);

		return implode('-', $parts);
	}

	/**
	 * @throws InvalidArgumentException For a part outside the image
	 */
	protected static function part(Rect $part, Size $size): Rect
	{
		if (
			$part->x < 0 ||
			$part->y < 0 ||
			$part->width < 1 ||
			$part->height < 1 ||
			$part->x + $part->width > $size->width ||
			$part->y + $part->height > $size->height
		) {
			throw new InvalidArgumentException(
				message: 'Crop outside the image'
			);
		}

		return $part;
	}

	/**
	 * The rectangle to cut, as decoded, from the source at full
	 * size or shrunk to `$decoded`, which must cover `::scale()`
	 */
	public function region(Size|null $decoded = null): Rect
	{
		$original = $this->source->size();
		$target   = $original;

		if ($decoded !== null) {
			$target = $this->orientation->apply($decoded);
		}

		$x = static::rescale($this->crop->x, $original->width, $target->width);
		$y = static::rescale($this->crop->y, $original->height, $target->height);

		$width  = static::rescale($this->crop->width, $original->width, $target->width);
		$height = static::rescale($this->crop->height, $original->height, $target->height);

		$rect = new Rect(
			x:      min($x, $target->width - $width),
			y:      min($y, $target->height - $height),
			width:  $width,
			height: $height
		);

		return $this->orientation->map($rect, $target);
	}

	public function resample(): Size
	{
		return $this->orientation->apply($this->size);
	}

	protected static function rescale(
		int $value,
		int $from,
		int $to
	): int {
		if ($from === 0) {
			return 0;
		}

		return (int)round($value * $to / $from);
	}

	public function scale(): Size
	{
		$original = $this->source->size();
		$scale    = new Size(
			static::rescale($original->width, $this->crop->width, $this->size->width),
			static::rescale($original->height, $this->crop->height, $this->size->height)
		);

		return $this->orientation->apply($scale);
	}

	public function size(): Size
	{
		return $this->size;
	}

	public function toArray(): array
	{
		return [
			'quality'   => $this->quality,
			'format'    => $this->format->extension(),
			'interlace' => $this->interlace,
			'icc'       => $this->icc,
			...$this->options->toArray(),
		];
	}
}
