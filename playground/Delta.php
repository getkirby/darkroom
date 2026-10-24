<?php

namespace Darkroom\Playground;

use Imagick;
use ImagickException;

/**
 * Compares the drivers' thumbs of a case with each other:
 * a thumb's Δ is its difference to the closest of the others
 */
final class Delta
{
	public const float LIMIT = 0.1;

	/**
	 * Adds each thumb's differences to the others,
	 * its Δ, and an issue if it differs from all
	 *
	 * @param array<string, array> $cells By driver
	 * @param $dir Holds the thumbs
	 */
	public static function add(array $cells, string $dir): array
	{
		$frames = [];

		foreach ($cells as $driver => $cell) {
			if (isset($cell['url']) === true) {
				$frames[$driver] = static::frames($dir . '/' . basename($cell['url']));
			}
		}

		// what Imagick can't read can't be compared
		$frames = array_filter($frames);

		foreach ($frames as $driver => $own) {
			$differences = [];

			foreach ($frames as $other => $theirs) {
				if ($other !== $driver) {
					$differences[$other] = static::difference($own, $theirs);
				}
			}

			$delta = $differences === [] ? null : min($differences);

			$cells[$driver]['differences'] = $differences;
			$cells[$driver]['delta']       = $delta;

			if ($delta !== null && $delta >= self::LIMIT) {
				$cells[$driver]['state']    = 'issues';
				$cells[$driver]['issues'][] = 'differs from every other driver';
			}
		}

		return $cells;
	}

	/**
	 * Root mean square error of the most different frame
	 * both have, from 0 to 1
	 *
	 * @param list<Imagick> $a
	 * @param list<Imagick> $b
	 */
	protected static function difference(array $a, array $b): float
	{
		$metric = Imagick::METRIC_ROOTMEANSQUAREDERROR;
		$worst  = 0.0;

		for ($index = 0; $index < min(count($a), count($b)); $index++) {
			if ($a[$index]->getImageGeometry() !== $b[$index]->getImageGeometry()) {
				return 1.0;
			}

			$worst = max($worst, $a[$index]->getImageDistortion($b[$index], $metric));
		}

		return round($worst, 4);
	}

	/**
	 * Every frame in sRGB, flattened on magenta,
	 * which shows where alpha differs
	 *
	 * @return list<Imagick>|null
	 */
	protected static function frames(string $file): array|null
	{
		if (extension_loaded('imagick') === false) {
			return null;
		}

		try {
			$image = new Imagick($file);
			$image = $image->coalesceImages();
		} catch (ImagickException) {
			return null;
		}

		$frames = [];

		foreach ($image as $frame) {
			$frame = $frame->getImage();
			$frame->setImagePage(0, 0, 0, 0);
			$frame->transformImageColorspace(Imagick::COLORSPACE_SRGB);
			$frame->setImageBackgroundColor('magenta');
			$frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

			$frames[] = $frame;
		}

		return $frames;
	}
}
