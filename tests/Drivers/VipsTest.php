<?php

namespace Darkroom\Tests\Drivers;

use Darkroom\Darkroom;
use Darkroom\Driver;
use Darkroom\Drivers\Vips;
use Darkroom\Exception\LimitException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Source;
use FFI;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[CoversClass(Vips::class)]
class VipsTest extends DriverTestCase
{
	// libvips converts CMYK through a press profile, the reference by formula
	protected const float CMYK = 0.2;

	protected function driver(): Driver
	{
		return new Vips();
	}

	public static function canAnimateProvider(): array
	{
		return [
			'gif'  => [Format::Gif, true],
			'webp' => [Format::Webp, true],
			'avif' => [Format::Avif, false],
			'heic' => [Format::Heic, false],
			'jpeg' => [Format::Jpeg, false],
			'png'  => [Format::Png, false],
		];
	}

	#[DataProvider('canAnimateProvider')]
	public function testCanAnimate(Format $format, bool $expected): void
	{
		$this->assertSame($expected, $this->driver->canAnimate($format));
	}

	public static function canReadProvider(): array
	{
		return [
			'gif'  => [Format::Gif],
			'jpeg' => [Format::Jpeg],
			'png'  => [Format::Png],
			'webp' => [Format::Webp],
		];
	}

	#[DataProvider('canReadProvider')]
	public function testCanRead(Format $format): void
	{
		$this->assertTrue($this->driver->canRead($format));
	}

	public function testCanReadAnimation(): void
	{
		$fixture = static::FIXTURES . '/gif/animated.gif';
		$file    = $this->file('animated.gif');
		$gif     = new Source($fixture);
		$missing = new Source($file);

		copy($fixture, $file);
		$missing->format();
		$missing->isAnimated();
		unlink($file);

		$this->assertFalse($this->driver->canRead($missing));
		$this->assertTrue($this->driver->canRead($gif));

		// the first animation that libvips reads tells for its format
		$this->assertTrue($this->driver->canRead($missing));
	}

	public static function canReadHeifProvider(): array
	{
		return [
			'avif' => ['heif/plain.avif'],
			'heic' => ['heif/plain.heic'],
		];
	}

	#[DataProvider('canReadHeifProvider')]
	public function testCanReadHeif(string $fixture): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);
		$format = $source->format();

		// black where the decoder failed without an error
		try {
			$image = Image::newFromFile($source->file);
			$loads = $image->avg() > 0;
		} catch (VipsException) {
			$loads = false;
		}

		$this->assertSame($loads, $this->driver->canRead($format));
	}

	public static function canReadSourceProvider(): array
	{
		return [
			'gif'           => ['gif/animated.gif', true],
			'webp'          => ['webp/animated.webp', true],
			'avif'          => ['heif/plain.avif', true],
			'png'           => ['png/plain.png', true],
			'heic sequence' => ['heif/animated.heic', true],
			'apng'          => ['png/animated.png', true],
			'hdr'           => ['heif/hdr.avif', false],
		];
	}

	#[DataProvider('canReadSourceProvider')]
	public function testCanReadSource(string $fixture, bool $expected): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);
		$format = $source->format();

		if ($this->driver->canRead($format) === false) {
			$this->skip('Can\'t read ' . $format->extension(), $this->driver, $format);
		}

		$this->assertSame($expected, $this->driver->canRead($source));
	}

	public function testCanReadSourceJxl(): void
	{
		$source = new Source(static::FIXTURES . '/jxl/animated.jxl');

		if ($this->driver->canRead(Format::Jxl) === false) {
			$this->skip('Can\'t read jxl', $this->driver, Format::Jxl);
		}

		$header   = Image::newFromFile($source->file);
		$expected = $header->typeOf('delay') !== 0;

		$this->assertSame($expected, $this->driver->canRead($source));
	}

	public function testCanReadSourceSequence(): void
	{
		$source = new Source(static::FIXTURES . '/heif/animated.avif');

		if ($this->driver->canRead(Format::Avif) === false) {
			$this->skip('Can\'t read avif', $this->driver, Format::Avif);
		}

		try {
			Image::newFromFile($source->file);
			$opens = true;
		} catch (VipsException) {
			$opens = false;
		}

		$this->assertSame($opens, $this->driver->canRead($source));
	}

	#[DataProvider('canReadProvider')]
	public function testCanWrite(Format $format): void
	{
		$this->assertTrue($this->driver->canWrite($format));
	}

	public function testInfo(): void
	{
		$info = $this->driver->info();

		$this->assertSame('libvips', $info['library']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $info['version']);
	}

	public function testIsAvailable(): void
	{
		$this->assertTrue($this->driver->isAvailable());
	}

	public static function isAvailableLanguageProvider(): array
	{
		return [
			'unset' => [null, 'C'],
			'empty' => ['', 'C'],
			'set'   => ['de_DE.UTF-8', 'de_DE.UTF-8'],
		];
	}

	#[DataProvider('isAvailableLanguageProvider')]
	#[RunInSeparateProcess]
	public function testIsAvailableLanguage(string|null $language, string $expected): void
	{
		$vips = new Vips();

		putenv($language === null ? 'LANG' : 'LANG=' . $language);

		$this->assertTrue($vips->isAvailable());
		$this->assertSame($expected, getenv('LANG'));
	}

	public static function orientsProvider(): array
	{
		return [
			'avif' => ['heif/irot-1.avif', true],
			'heic' => ['heif/exif.heic', true],
			'jxl'  => ['jxl/exif-6.jxl', true],
			'jpeg' => ['orientation/Landscape_6.jpg', false],
			'png'  => ['png/exif-6.png', false],
			'webp' => ['webp/exif-6.webp', false],
		];
	}

	#[DataProvider('orientsProvider')]
	public function testOrients(string $fixture, bool $expected): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);

		$this->assertSame($expected, $this->driver->orients($source));
	}

	public static function processLimitProvider(): array
	{
		// three frames of 30×20
		return [
			'within' => [1800, false],
			'above'  => [1799, true],
		];
	}

	#[DataProvider('processLimitProvider')]
	public function testProcessLimit(int $limit, bool $throws): void
	{
		$source   = static::FIXTURES . '/gif/animated.gif';
		$file     = $this->file('thumb.gif');
		$darkroom = new Darkroom(['vips'], limit: $limit);

		if ($throws === true) {
			$this->expectException(LimitException::class);
			$this->expectExceptionMessage('Frames above the limit of 1799 pixels: ' . $source);
		}

		$plan = $darkroom->process($source, $file, ['width' => 15]);

		$this->assertTrue($plan->frames);
	}

	public function testProcessReplaced(): void
	{
		$source   = $this->file('replaced');
		$file     = $this->file('thumb.gif');
		$darkroom = new Darkroom([$this->driver]);
		$animated = [];

		foreach (['gif/still.gif', 'gif/animated.gif'] as $fixture) {
			copy(static::FIXTURES . '/' . $fixture, $source);

			$darkroom->process($source, $file, ['width' => 10]);

			$output     = new Source($file);
			$animated[] = $output->isAnimated();
		}

		$this->assertSame([false, true], $animated);
	}

	public function testProcessRotated(): void
	{
		$source   = $this->file('rotated.jpg');
		$file     = $this->file('thumb.jpg');
		$jpeg     = static::jpeg(1200, 800, Orientation::Rotate90);
		$darkroom = new Darkroom([$this->driver]);

		file_put_contents($source, $jpeg);

		$plan = $darkroom->process($source, $file, ['width' => 800]);

		$output = new Source($file);

		$this->assertEquals($plan->size, $output->size());
	}

	public static function processThreadsProvider(): array
	{
		return [
			'many' => [16, 4],
			'few'  => [2, 2],
		];
	}

	#[DataProvider('processThreadsProvider')]
	public function testProcessThreads(int $cores, int $expected): void
	{
		$source  = static::FIXTURES . '/png/plain.png';
		$file    = $this->file('thumb.png');
		$thumb   = ['width' => 10];
		$default = new Darkroom([$this->driver]);
		$set     = new Darkroom([new Vips(['threads' => 6])]);
		$vips    = FFI::cdef('int vips_concurrency_get(void);');

		Config::concurrencySet($cores);
		$default->process($source, $file, $thumb);

		$this->assertSame($expected, $vips->vips_concurrency_get());

		$set->process($source, $file, $thumb);

		$this->assertSame(6, $vips->vips_concurrency_get());

		$default->process($source, $file, $thumb);

		$this->assertSame($expected, $vips->vips_concurrency_get());
	}
}
