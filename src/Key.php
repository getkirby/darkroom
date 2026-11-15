<?php

declare(strict_types = 1);

namespace Darkroom;

use InvalidArgumentException;
use Stringable;

/**
 * Names what a plan writes, without the format, and the options
 * that plan the same again; plans that write the same image
 * from a source share it
 */
final readonly class Key implements Stringable
{
	protected function __construct(
		protected string $value
	) {
	}

	public function __toString(): string
	{
		return $this->value;
	}

	public static function for(Plan $plan): static
	{
		$original = $plan->source->size();
		$options  = $plan->options;
		$crop     = $plan->crop;
		$size     = $plan->size();
		$parts    = [$size->width . 'x' . $size->height];

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

		$parts[] = 'q' . $plan->quality;

		if ($plan->interlace === true) {
			$parts[] = 'interlace';
		}

		// what it keeps of the source, unless the default
		if ($plan->meta !== Defaults::META) {
			$meta    = array_column($plan->meta, 'value');
			$parts[] = $meta === [] ? 'nometa' : 'meta-' . implode('-', $meta);
		}

		return new static(implode('-', $parts));
	}

	/**
	 * @throws InvalidArgumentException For an invalid key
	 */
	public static function from(string $key): static
	{
		$pattern = self::pattern();

		if (preg_match($pattern, $key) !== 1) {
			throw new InvalidArgumentException(
				message: 'Invalid key: ' . $key
			);
		}

		return new static($key);
	}

	/**
	 * The parts of a key in order, each optional but size and quality
	 *
	 * @return non-empty-string
	 */
	protected static function pattern(): string
	{
		$names = implode('|', array_column(Meta::cases(), 'value'));

		return '/^(\d+)x(\d+)(?:-crop(\d+)-(\d+)-(\d+)-(\d+))?(?:-blur(\d+))?(?:-sharpen(\d+))?(-grayscale)?-q(\d+)(-interlace)?(-nometa|-meta(?:-(?:' . $names . '))+)?$/';
	}

	/**
	 * The options the key names, all set
	 * so that defaults change nothing
	 */
	public function toOptions(): Options
	{
		$pattern = self::pattern();
		$crop    = null;

		preg_match($pattern, $this->value, $match, PREG_UNMATCHED_AS_NULL);

		if ($match[3] !== null) {
			$crop = new Rect(
				x:      (int)$match[3],
				y:      (int)$match[4],
				width:  (int)$match[5],
				height: (int)$match[6]
			);
		}

		// none for the default, else after `-meta-`
		$meta = match ($match[12]) {
			null      => array_column(Defaults::META, 'value'),
			'-nometa' => [],
			default   => explode('-', substr($match[12], 6))
		};

		return Options::from([
			'width'     => $match[1],
			'height'    => $match[2],
			'crop'      => $crop,
			'blur'      => $match[7],
			'sharpen'   => $match[8],
			'grayscale' => $match[9] !== null,
			'quality'   => $match[10],
			'interlace' => $match[11] !== null,
			'meta'      => $meta,
		]);
	}

	public function toString(): string
	{
		return $this->value;
	}
}
