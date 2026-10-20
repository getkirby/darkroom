<?php

namespace Darkroom\Bench;

use Darkroom\Options;
use Darkroom\Plan;
use Darkroom\Source;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * What a page runs per options: headers only, no decoding; on the
 * fixtures, as a header costs the same at any image size
 */
#[Iterations(10)]
#[ParamProviders('images')]
#[Revs(1000)]
#[Warmup(1)]
final class PlanBench
{
	private const string FIXTURES = __DIR__ . '/../tests/fixtures';

	public function benchCrop(array $params): void
	{
		$source = new Source($params['file']);
		$options  = Options::from(['width' => 800, 'height' => 600, 'crop' => true]);

		Plan::for($source, $options);
	}

	public function benchRead(array $params): void
	{
		$source = new Source($params['file']);
		$source->size();
		$source->orientation();
	}

	public function benchResize(array $params): void
	{
		$source = new Source($params['file']);
		$options  = Options::from(['width' => 800]);

		Plan::for($source, $options);
	}

	/**
	 * One per format, with an orientation where the format has one
	 */
	public function images(): array
	{
		$files = [
			'avif' => 'heif/exif.avif',
			'gif'  => 'gif/animated.gif',
			'heic' => 'heif/exif.heic',
			'jpeg' => 'orientation/Landscape_6.jpg',
			'jxl'  => 'jxl/exif-6.jxl',
			'png'  => 'png/exif-6.png',
			'webp' => 'webp/exif-6.webp',
		];

		return array_map(fn (string $file) => ['file' => self::FIXTURES . '/' . $file], $files);
	}
}
