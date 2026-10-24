<?php

namespace Darkroom\Bench;

use Darkroom\Darkroom;
use Darkroom\Driver;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * One thumb of a large image per iteration, in a subclass per driver
 */
#[Iterations(5)]
#[OutputTimeUnit('milliseconds')]
#[Revs(1)]
abstract class DriverBench
{
	#[ParamProviders('photos')]
	public function benchCrop(array $params): void
	{
		$this->process($params['file'], [
			'width'  => 800,
			'height' => 600,
			'crop'   => true
		]);
	}

	#[ParamProviders('gifs')]
	public function benchGif(array $params): void
	{
		$this->process($params['file'], ['width' => 800]);
	}

	#[ParamProviders('photos')]
	public function benchResize(array $params): void
	{
		$this->process($params['file'], ['width' => 800]);
	}

	/**
	 * A further thumb in the same process, past the libraries' start
	 */
	#[ParamProviders('images')]
	#[Warmup(1)]
	public function benchWarm(array $params): void
	{
		$this->process($params['file'], ['width' => 800]);
	}

	abstract protected function driver(): Driver;

	public function gifs(): array
	{
		return ['gif' => ['file' => Image::Gif->file()]];
	}

	public function images(): array
	{
		return [...$this->photos(), ...$this->gifs()];
	}

	public function photos(): array
	{
		return [
			'jpeg'   => ['file' => Image::Jpeg->file()],
			'exif 6' => ['file' => Image::Rotated->file()],
			'photo'  => ['file' => Image::Photo->file()],
		];
	}

	protected function process(string $file, array $options): void
	{
		$driver   = $this->driver();
		$darkroom = new Darkroom([$driver]);
		$target   = dirname($file) . '/thumbs/' . basename($file);

		$darkroom->process($file, $target, $options);
	}
}
