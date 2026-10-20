<?php

namespace Darkroom;

use Darkroom\Exception\UnsupportedException;

/**
 * What a driver needs, computed before decoding
 */
final readonly class Plan
{
	/**
	 * @param $options The request it was planned from
	 * @param $crop The part of the source kept, as displayed
	 * @param $size The thumb's size, as displayed
	 * @param $orientation Turns the resized region into the thumb, as displayed
	 * @param $frames Keep all frames
	 */
	private function __construct(
		public Source $source,
		public Options $options,
		public Rect $crop,
		public Size $size,
		public Orientation $orientation,
		public bool $frames,
		public Format $format,
		public int $quality,
		public bool $interlace,
		public bool $icc,
	) {
	}

	/**
	 * The largest rectangle with the ratio of `$size`,
	 * centred on `$focus` as far as the edges allow
	 */
	private static function crop(
		Size $source,
		Size $size,
		Focus $focus
	): Rect {
		// multiplied before dividing, to stay exact
		[$width, $height] = match ($size->width * $source->height <=> $size->height * $source->width) {
			1 => [
				$source->width,
				max(1, (int)floor($source->width * $size->height / $size->width))
			],
			-1 => [
				max(1, (int)floor($source->height * $size->width / $size->height)),
				$source->height
			],
			0 => [
				$source->width,
				$source->height
			]
		};

		// placed by its whole length, so a crop at an edge reaches it
		$start = fn (
			float $total,
			float $length,
			float $point
		): int => (int)min(
			max(0.0, round($total * $point - $length / 2.0)),
			$total - $length
		);

		return new Rect(
			x:      $start($source->width, $width, $focus->x),
			y:      $start($source->height, $height, $focus->y),
			width:  $width,
			height: $height
		);
	}

	/**
	 * Without `$driver`, for the output size and format only
	 *
	 * @throws UnsupportedException If the file is no image or can't be read
	 */
	public static function for(
		Source $source,
		Options $options,
		Defaults $defaults = new Defaults(),
		Driver|null $driver = null
	): static {
		$input     = $source->format();
		$displayed = $source->size();
		$focus     = $options->focus();

		if ($focus === null) {
			$size = $displayed->fit($options->width, $options->height);
			$crop = new Rect(0, 0, $displayed->width, $displayed->height);
		} else {
			// a missing side takes the other's
			$requested = new Size(
				width:
					$options->width ??
					$options->height ??
					$displayed->width,
				height:
					$options->height ??
					$options->width ??
					$displayed->height
			);
			$crop = static::crop($displayed, $requested, $focus);

			// never enlarged: shrunk into the rectangle where it is smaller
			$size = $requested->fit($crop->width, $crop->height);
		}

		$format = $options->format ?? $defaults->format ?? $input;

		// what the decoder leaves undone
		$orientation = $driver?->orients($source) === true ? Orientation::None : $source->orientation();

		// the cached probe first, as finding frames reads the file
		$frames =
			$driver?->canAnimate($format) === true &&
			$source->isAnimated() === true;

		// the thumb's, else the darkroom's, else the library's
		return new static(
			source:      $source,
			options:     $options,
			crop:        $crop,
			size:        $size,
			orientation: $orientation,
			frames:      $frames,
			format:      $format,
			quality:     $options->quality ?? $defaults->quality ?? Defaults::QUALITY,
			interlace:   $options->interlace ?? $defaults->interlace ?? Defaults::INTERLACE,
			icc:         $options->icc ?? $defaults->icc ?? Defaults::ICC
		);
	}

	/**
	 * The size to ask a JPEG decoder for, as decoded:
	 * half an eighth below the smallest M/8 of the source
	 * that covers 1.5 × `::scale()`, at least `::scale()`;
	 * decoders that round it to the nearest eighth load M/8,
	 * those that scale by the 1/n that covers it at least M/8;
	 * the resize then has pixels to filter
	 */
	public function hint(): Size
	{
		$displayed = $this->source->size();
		$full      = $this->orientation->apply($displayed);

		if ($full->pixels() === 0) {
			return $full;
		}

		$scale = $this->scale();

		$eighths = (int)min(8, max(
			ceil(12 * $scale->width / $full->width),
			ceil(12 * $scale->height / $full->height)
		));

		return new Size(
			max($scale->width, (int)ceil($full->width * (2 * $eighths - 1) / 16)),
			max($scale->height, (int)ceil($full->height * (2 * $eighths - 1) / 16))
		);
	}

	/**
	 * The rectangle to cut, as decoded, from the source at
	 * full size or at `$decoded`, which must cover `::scale()`
	 */
	public function region(Size|null $decoded = null): Rect
	{
		$full = $this->source->size();

		// as displayed, so every orientation keeps the same pixels
		$shown = $decoded === null ? $full : $this->orientation->apply($decoded);

		// each rounded on its own; the position keeps it inside
		$x = static::rescale($this->crop->x, $full->width, $shown->width);
		$y = static::rescale($this->crop->y, $full->height, $shown->height);

		$width  = static::rescale($this->crop->width, $full->width, $shown->width);
		$height = static::rescale($this->crop->height, $full->height, $shown->height);

		$rect = new Rect(
			x:      min($x, $shown->width - $width),
			y:      min($y, $shown->height - $height),
			width:  $width,
			height: $height
		);

		return $this->orientation->map($rect, $shown);
	}

	/**
	 * The size to resize `::region()` to, as decoded
	 */
	public function resample(): Size
	{
		// sizes swap the same way in both directions
		return $this->orientation->apply($this->size);
	}

	private static function rescale(
		int $value,
		int $from,
		int $to
	): int {
		if ($from === 0) {
			return 0;
		}

		return (int)round($value * $to / $from);
	}

	/**
	 * The size to load the source at, as decoded, so that `::region()`
	 * has the size of `::resample()`; never larger than the source
	 */
	public function scale(): Size
	{
		$full = $this->source->size();

		// each side on its own, so `::region()` has the size of `::resample()`
		$scale = new Size(
			static::rescale($full->width, $this->crop->width, $this->size->width),
			static::rescale($full->height, $this->crop->height, $this->size->height)
		);

		return $this->orientation->apply($scale);
	}
}
