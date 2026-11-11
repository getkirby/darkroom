<?php

namespace Darkroom\Tests\Drivers;

use Darkroom\Darkroom;
use Darkroom\Driver;
use Darkroom\Drivers\Imagick;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Format;
use Darkroom\Options;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Size;
use Darkroom\Source;
use Darkroom\Tools\Icc;
use Imagick as Image;
use ImagickException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[CoversClass(Imagick::class)]
class ImagickTest extends DriverTestCase
{
	protected static function cmyk(): string
	{
		$identity = pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x10000);
		$ramp     = pack('n2', 0, 0xFFFF);
		$gray     = pack('n3', 0x7F80, 0x8000, 0x8000);

		$lut =
			'mft2' . "\0\0\0\0" . "\x04\x03\x02\0" . $identity . pack('n2', 2, 2) .
			str_repeat($ramp, 4) . str_repeat($gray, 16) . str_repeat($ramp, 3);

		$tags = pack('N', 1) . 'A2B0' . pack('N2', 144, strlen($lut));

		$header =
			pack('N', 144 + strlen($lut)) . "\0\0\0\0" . "\x02\x10\0\0" . 'scnrCMYKLab ' .
			str_repeat("\0", 12) . 'acsp' . str_repeat("\0", 28) .
			pack('N3', 0xF6D6, 0x10000, 0xD32D) . str_repeat("\0", 48);

		return $header . $tags . $lut;
	}

	protected function converts(): bool
	{
		$options = Image::getConfigureOptions('DELEGATES');

		return str_contains($options['DELEGATES'] ?? '', 'lcms');
	}

	protected function driver(): Driver
	{
		return new Imagick();
	}

	protected function hdr(): bool
	{
		$image = new Image();
		$image->pingImage('AVIF:' . static::FIXTURES . '/heif/hdr.avif');

		return $image->getImageProperties('heic:cicp', false) !== [];
	}

	protected static function limits(): array
	{
		$types = [
			Image::RESOURCETYPE_DISK,
			Image::RESOURCETYPE_HEIGHT,
			Image::RESOURCETYPE_LISTLENGTH,
			Image::RESOURCETYPE_THREAD,
			Image::RESOURCETYPE_WIDTH,
		];

		return array_map(Image::getResourceLimit(...), $types);
	}

	protected static function quality(): bool
	{
		$image = new Image(static::FIXTURES . '/orientation/Landscape_1.jpg');
		$sizes = [];

		$image->setImageFormat('webp');

		foreach ([20, 90] as $quality) {
			$image->setImageCompressionQuality($quality);
			$sizes[] = strlen($image->getImageBlob());
		}

		return $sizes[0] < $sizes[1];
	}

	public static function canAnimateProvider(): array
	{
		return [
			'gif'  => [Format::Gif, true],
			'jpeg' => [Format::Jpeg, false],
			'png'  => [Format::Png, false],
			'webp' => [Format::Webp, true],
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

	/**
	 * libheif loads its decoders as plugins, which a system may lack
	 */
	public static function canReadDecoderProvider(): array
	{
		return [
			'avif' => ['heif/irot-1.avif', Format::Avif],
			'heic' => ['heif/exif.heic', Format::Heic],
		];
	}

	#[DataProvider('canReadDecoderProvider')]
	public function testCanReadDecoder(string $fixture, Format $format): void
	{
		$image = new Image();

		try {
			$decodes = $image->readImage(static::FIXTURES . '/' . $fixture);
		} catch (ImagickException) {
			$decodes = false;
		}

		$this->assertSame($decodes, $this->driver->canRead($format));
	}

	public function testCanReadSourceHdr(): void
	{
		$source = new Source(static::FIXTURES . '/heif/hdr.avif');

		if ($this->driver->canRead(Format::Avif) === false) {
			$this->skip('Can\'t read avif', $this->driver, Format::Avif);
		}

		$this->assertTrue($this->driver->canRead($source));
	}

	#[DataProvider('canReadProvider')]
	public function testCanWrite(Format $format): void
	{
		$this->assertTrue($this->driver->canWrite($format));
	}

	public function testInfo(): void
	{
		$info    = $this->driver->info();
		$version = Image::getVersion()['versionString'];

		$this->assertSame('ImageMagick', $info['library']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+-\d+$/', $info['version']);
		$this->assertStringContainsString(' ' . $info['version'] . ' ', $version);
	}

	public function testIsAvailable(): void
	{
		$this->assertTrue($this->driver->isAvailable());
	}

	public static function orientsProvider(): array
	{
		return [
			'avif' => ['heif/irot-1.avif', true],
			'heic' => ['heif/exif.heic', true],
			'jpeg' => ['orientation/Landscape_6.jpg', false],
			'jxl'  => ['jxl/exif-6.jxl', false],
			'webp' => ['webp/exif-6.webp', false],
		];
	}

	#[DataProvider('orientsProvider')]
	public function testOrients(string $fixture, bool $expected): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);

		$this->assertSame($expected, $this->driver->orients($source));
	}

	public function testProcessCmykProfile(): void
	{
		if ($this->converts() === false) {
			$this->markTestSkipped('ImageMagick has no LCMS');
		}

		$jpeg     = file_get_contents(static::FIXTURES . '/cmyk.jpg');
		$cmyk     = static::cmyk();
		$tagged   = Icc::embed($jpeg, Format::Jpeg, $cmyk);
		$source   = $this->file('cmyk.jpg', $tagged);
		$file     = $this->file('thumb.jpg');
		$darkroom = new Darkroom([$this->driver]);

		$darkroom->process($source, $file, static::OPTIONS);

		$image    = new Image($file);
		$pixel    = $image->getImagePixelColor(20, 15);
		$color    = $pixel->getColor();
		$profiles = $image->getImageProfiles('icc');

		// L* 50 in sRGB, where the formula would keep the colors
		$this->assertEqualsWithDelta(119, $color['r'], 2);
		$this->assertEqualsWithDelta(119, $color['g'], 2);
		$this->assertEqualsWithDelta(119, $color['b'], 2);
		$this->assertSame('RGB ', substr($profiles['icc'], 16, 4));
	}

	public function testProcessFrameLimit(): void
	{
		$source = static::FIXTURES . '/gif/animated.gif';
		$file   = $this->file('thumb.gif');

		// three frames of 30×20 pixels
		$within = new Darkroom(['imagick'], settings: ['limit' => 1800]);
		$above  = new Darkroom(['imagick'], settings: ['limit' => 1799]);

		$within->process($source, $file, static::OPTIONS);

		$this->assertFileExists($file);
		$this->expectException(LimitException::class);

		$above->process($source, $file, static::OPTIONS);
	}

	public static function processFrameSizeProvider(): array
	{
		// a frame of 100×100 on a smaller screen
		return [
			'within'         => [[2, 2], 10_000, null],
			'above the side' => [[2, 2], 9999, ProcessException::class],
			'above the area' => [[100, 1], 2500, LimitException::class],
		];
	}

	#[DataProvider('processFrameSizeProvider')]
	public function testProcessFrameSize(
		array $screen,
		int $limit,
		string|null $exception
	): void {
		$gif      = static::grey(100, 100, 'imagegif');
		$small    = substr_replace($gif, pack('vv', ...$screen), 6, 4);
		$source   = $this->file('frame.gif', $small);
		$file     = $this->file('thumb.gif');
		$darkroom = new Darkroom(['imagick'], settings: ['limit' => $limit]);

		if ($exception !== null) {
			$this->expectException($exception);
		}

		$darkroom->process($source, $file, static::OPTIONS);

		$this->assertFileExists($file);
	}

	public static function processHintProvider(): array
	{
		return [
			'landscape' => [1800, 1200, Orientation::None],
			'portrait'  => [1200, 1800, Orientation::None],
			'odd'       => [1001, 667, Orientation::None],
			'exif 6'    => [1800, 1200, Orientation::Rotate90],
		];
	}

	#[DataProvider('processHintProvider')]
	public function testProcessHint(
		int $width,
		int $height,
		Orientation $orientation
	): void {
		$jpeg   = static::jpeg($width, $height, $orientation);
		$file   = $this->file('photo.jpg', $jpeg);
		$source = new Source($file);
		$size   = $source->size();
		$thumbs = static::thumbs($size->width);

		foreach ($thumbs as $values) {
			$options = Options::from($values);
			$plan  = Plan::for($source, $options, driver: $this->driver);
			$hint  = $plan->jpegSize();
			$scale = $plan->scale();
			$image = new Image();

			$image->setOption('jpeg:size', $hint->width . 'x' . $hint->height);
			$image->readImage('JPEG:' . $file);

			$decoded = new Size(
				$image->getImageWidth(),
				$image->getImageHeight()
			);
			$size    = $decoded->width . '×' . $decoded->height;
			$message = json_encode($values) . ' decoded at ' . $size;

			$this->assertGreaterThanOrEqual($scale->width, $decoded->width, $message);
			$this->assertGreaterThanOrEqual($scale->height, $decoded->height, $message);
			$this->assertLessThanOrEqual($width, $decoded->width, $message);
			$this->assertLessThanOrEqual($height, $decoded->height, $message);
		}
	}

	public function testProcessLayers(): void
	{
		$file  = $this->file('thumb.gif');
		$image = new Image();
		$areas = [];

		$plan = $this->process('gif/partial.gif', $file, static::OPTIONS);

		$image->pingImage($file);

		foreach ($image as $frame) {
			$areas[] = $frame->getImageWidth() * $frame->getImageHeight();
		}

		$screen = $plan->size()->pixels();

		$this->assertCount(3, $areas);
		$this->assertSame($screen, $areas[0]);
		$this->assertLessThan($screen, max($areas[1], $areas[2]));
	}

	public function testProcessLimits(): void
	{
		$data     = static::bare(30, 20);
		$broken   = $this->file('broken.png', $data);
		$gif      = $this->file('thumb.gif');
		$png      = $this->file('thumb.png');
		$darkroom = new Darkroom([$this->driver]);
		$limits   = static::limits();

		$this->process('gif/animated.gif', $gif, static::OPTIONS);

		$this->assertSame($limits, static::limits());

		try {
			$darkroom->process($broken, $png, ['width' => 10]);
		} catch (ProcessException) {
		}

		$this->assertSame($limits, static::limits());
	}

	#[DataProvider('processQualityProvider')]
	public function testProcessQuality(string $extension): void
	{
		if ($extension === 'webp' && static::quality() === false) {
			$this->markTestSkipped('ImageMagick ignores the quality of WebP');
		}

		parent::testProcessQuality($extension);
	}

	#[DataProvider('processStillProvider')]
	public function testProcessStill(string $fixture): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);
		$image  = new Image();

		$image->setOption('heic:primary-image', 'true');
		$image->pingImage($source->file . '[0]');

		if ($image->getImageWidth() !== $source->size()->width) {
			$this->markTestSkipped('ImageMagick reads the coded frames of ' . $fixture);
		}

		parent::testProcessStill($fixture);
	}

	#[RunInSeparateProcess]
	public function testProcessTime(): void
	{
		$file = $this->file('thumb.jpg');

		ini_set('max_execution_time', '100');

		$this->process('orientation/Landscape_1.jpg', $file, static::OPTIONS);

		$limit = Image::getResourceLimit(Image::RESOURCETYPE_TIME);

		$this->assertSame(100.0, $limit);
	}

	protected static function thumbs(int $width): array
	{
		$thumbs = [];

		for ($eighth = 1; $eighth <= 8; $eighth++) {
			for ($step = -1; $step <= 1; $step++) {
				$side = min($width, (int)round($eighth * $width / 8) + $step);

				$thumbs[] = ['width' => $side];
				$thumbs[] = ['width' => $side, 'height' => $side, 'crop' => true];
				$thumbs[] = ['width' => $side, 'height' => intdiv($side, 3) + 1, 'crop' => true];
			}
		}

		return $thumbs;
	}
}
