<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * Output settings of every thumb; a thumb's own quality and interlace come first
 */
final readonly class Defaults
{
	/**
	 * @param $quality 1–100
	 * @param $icc Keep the color profile
	 */
	public function __construct(
		public int $quality = 90,
		public bool $interlace = false,
		public bool $icc = true,
	) {
	}

	/**
	 * From settings, by the rules of thumb options; other keys are ignored
	 *
	 * @throws InvalidArgumentException For an invalid value
	 */
	public static function from(array $settings): static
	{
		$default = new static();
		$thumb   = Thumb::from([
			'quality'   => $settings['quality'] ?? null,
			'interlace' => $settings['interlace'] ?? null,
		]);

		$icc = Thumb::flag('icc', $settings['icc'] ?? null);

		return new static(
			quality:   $thumb->quality ?? $default->quality,
			interlace: $thumb->interlace ?? $default->interlace,
			icc:       $icc ?? $default->icc
		);
	}
}
