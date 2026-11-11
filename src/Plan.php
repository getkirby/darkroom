<?php

declare(strict_types = 1);

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

	/**
	 * The part to cut, as displayed, and the size to scale it to
	 *
	 * @return array{Rect, Size}
	 */
	protected static function cut(
		Options $options,
		Size $original
	): array {
		if ($options->crop instanceof Rect) {
			$crop = static::part($options->crop, $original);
			$size = new Size($crop->width, $crop->height);

			// a missing side keeps the part's ratio
			if (
				$options->width === null ||
				$options->height === null
			) {
				return [
					$crop,
					$size->fit($options->width, $options->height)
				];
			}

			// both sides as asked, shrunk into the part
			$size = new Size($options->width, $options->height);

			return [
				$crop,
				$size->fit($crop->width, $crop->height)
			];
		}

		$focus = $options->focus();

		if ($focus === null) {
			return [
				new Rect(0, 0, $original->width, $original->height),
				$original->fit($options->width, $options->height)
			];
		}

		// a missing side makes a square
		$size = new Size(
			$options->width ?? $options->height ?? $original->width,
			$options->height ?? $options->width ?? $original->height
		);
		$area = $original->crop($size);
		$crop = $focus->place($area, $original);

		return [
			$crop,
			$size->fit($crop->width, $crop->height)
		];
	}

	public static function for(
		Source $source,
		Options $options,
		Defaults $defaults = new Defaults(),
		Driver|null $driver = null
	): static {
		$input    = $source->format();
		$original = $source->size();
		$output   = $options->format ?? $defaults->format ?? $input;

		[$crop, $size] = static::cut($options, $original);

		// the source first, as probing the driver costs more
		$frames =
			$driver !== null &&
			$source->isAnimated() === true &&
			$driver->canAnimate($output) === true;

		return new static(
			source:      $source,
			options:     $options,
			crop:        $crop,
			size:        $size,
			orientation: $driver?->orients($source) === true ? Orientation::None : $source->orientation(),
			frames:      $frames,
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
		$options  = $this->options;
		$crop     = $this->crop;
		$parts    = [$this->size->width . 'x' . $this->size->height];

		if (
			$crop->width !== $original->width ||
			$crop->height !== $original->height
		) {
			$parts[] = sprintf(
				'crop%d-%d-%d-%d',
				$crop->x,
				$crop->y,
				$crop->width,
				$crop->height
			);
		}

		if ($options->blur !== null) {
			$parts[] = 'blur' . $options->blur;
		}

		if ($options->sharpen !== null) {
			$parts[] = 'sharpen' . $options->sharpen;
		}

		if ($options->grayscale === true) {
			$parts[] = 'grayscale';
		}

		$parts[] = 'q' . $this->quality;

		if ($this->interlace === true) {
			$parts[] = 'interlace';
		}

		if ($this->icc === false) {
			$parts[] = 'noicc';
		}

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
