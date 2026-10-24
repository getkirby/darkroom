<?php

namespace Darkroom\Tests\Drivers;

use Darkroom\Darkroom;
use Darkroom\Driver;
use Darkroom\Drivers\Gd;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Format;
use Darkroom\Options;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Source;
use Darkroom\Tools\Icc;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[CoversClass(Gd::class)]
class GdTest extends DriverTestCase
{
	protected function driver(): Driver
	{
		return new Gd();
	}

	protected function hdr(): bool
	{
		return false;
	}

	protected static function iccp(int $type, string $data): string
	{
		$pixels = str_repeat("\x80", $type === 0 ? 30 : 90);
		$rows   = str_repeat("\0" . $pixels, 20);
		$ihdr   = self::chunk('IHDR', pack('NN', 30, 20) . "\x08" . chr($type) . "\0\0\0");
		$iccp   = self::chunk('iCCP', $data);
		$idat   = self::chunk('IDAT', gzcompress($rows));
		$iend   = self::chunk('IEND');

		return self::png($ihdr, $iccp, $idat, $iend);
	}

	protected function peak(
		string $source,
		string $file,
		array $options
	): int {
		$darkroom = new Darkroom([$this->driver]);

		ini_set('memory_limit', '128M');
		memory_reset_peak_usage();

		$memory = memory_get_usage();
		$darkroom->process($source, $file, $options);

		return memory_get_peak_usage() - $memory;
	}

	/**
	 * `magick -size 30x20 xc:none -fill red -draw 'rectangle 6,4 12,11'
	 * -fill blue -draw 'rectangle 16,4 21,11' transparent.gif`
	 */
	public static function processCanvasProvider(): array
	{
		$cases = parent::processCanvasProvider();

		$cases['gif frame'] = ['gif/transparent.gif'];

		return $cases;
	}

	public static function processMetadataProvider(): array
	{
		$cases = parent::processMetadataProvider();

		unset($cases['avif']);

		return $cases;
	}

	public static function processTransparencyProvider(): array
	{
		return [
			'gif'  => ['gif/transparent.gif', 'gif'],
			'webp' => ['gif/transparent.gif', 'webp'],
		];
	}

	protected function subsamples(): bool
	{
		$image = imagecreatetruecolor(16, 16);
		$file  = $this->file('probe.jpg');

		imagejpeg($image, $file, 90);

		return static::sampling($file) === '2x2,1x1,1x1';
	}

	protected function tearDown(): void
	{
		ini_restore('memory_limit');

		parent::tearDown();
	}

	public function testCanAnimate(): void
	{
		foreach (Format::cases() as $format) {
			$this->assertFalse($this->driver->canAnimate($format));
		}
	}

	public static function canReadProvider(): array
	{
		return [
			'gif'  => [Format::Gif, true],
			'heic' => [Format::Heic, false],
			'jpeg' => [Format::Jpeg, true],
			'jxl'  => [Format::Jxl, false],
			'png'  => [Format::Png, true],
			'webp' => [Format::Webp, true],
		];
	}

	#[DataProvider('canReadProvider')]
	public function testCanRead(Format $format, bool $expected): void
	{
		$this->assertSame($expected, $this->driver->canRead($format));
	}

	public function testCanReadSource(): void
	{
		$animated = new Source(static::FIXTURES . '/webp/animated.webp');
		$still    = new Source(static::FIXTURES . '/webp/alpha.webp');

		$expected = @imagecreatefromwebp($animated->file) !== false;

		$this->assertSame($expected, $this->driver->canRead($animated));
		$this->assertTrue($this->driver->canRead($still));
	}

	public function testCanReadSourceCanvas(): void
	{
		$framed = new Source(static::FIXTURES . '/gif/optimized.gif');
		$clap   = new Source(static::FIXTURES . '/heif/clap.avif');
		$still  = new Source(static::FIXTURES . '/gif/still.gif');

		$this->assertFalse($this->driver->canRead($framed));
		$this->assertFalse($this->driver->canRead($clap));
		$this->assertTrue($this->driver->canRead($still));
	}

	public function testCanReadSourceHdr(): void
	{
		$hdr   = new Source(static::FIXTURES . '/heif/hdr.avif');
		$plain = new Source(static::FIXTURES . '/heif/plain.avif');

		if ($this->driver->canRead(Format::Avif) === false) {
			$this->markTestSkipped('GD reads no AVIF');
		}

		$this->assertFalse($this->driver->canRead($hdr));
		$this->assertTrue($this->driver->canRead($plain));
	}

	#[DataProvider('canReadProvider')]
	public function testCanWrite(Format $format, bool $expected): void
	{
		$this->assertSame($expected, $this->driver->canWrite($format));
	}

	public function testCanWriteAvif(): void
	{
		$expected = function_exists('imageavif');

		$this->assertSame($expected, $this->driver->canRead(Format::Avif));
		$this->assertSame($expected, $this->driver->canWrite(Format::Avif));
	}

	public function testInfo(): void
	{
		$info = $this->driver->info();

		$this->assertSame('GD', $info['library']);
		$this->assertSame(gd_info()['GD Version'], $info['version']);
	}

	public function testIsAvailable(): void
	{
		$this->assertTrue($this->driver->isAvailable());
	}

	public function testProcessGifPalette(): void
	{
		$png      = static::grey(30, 20, 'imagepng');
		$source   = $this->file('grey.png', $png);
		$file     = $this->file('thumb.gif');
		$darkroom = new Darkroom([$this->driver]);

		$darkroom->process($source, $file, ['width' => 30]);

		$image = imagecreatefromgif($file);
		$index = imagecolorat($image, 15, 10);
		$color = imagecolorsforindex($image, $index);

		$this->assertSame([128, 128, 128], [$color['red'], $color['green'], $color['blue']]);
	}

	public function testProcessLimit(): void
	{
		$png      = static::bare(10000, 10000);
		$source   = $this->file('large.png', $png);
		$file     = $this->file('thumb.png');
		$darkroom = new Darkroom([$this->driver]);

		// decoding needs 400 MB
		ini_set('memory_limit', '256M');

		$this->expectException(LimitException::class);
		$this->expectExceptionMessage('Image too large for the memory limit: ' . $source);

		$darkroom->process($source, $file, ['width' => 100]);
	}

	public static function processMemoryProvider(): array
	{
		$crop   = ['width' => 800, 'height' => 600, 'crop' => true];
		$resize = ['width' => 800];

		return [
			'crop'           => [$crop, Orientation::None],
			'crop, exif 6'   => [$crop, Orientation::Rotate90],
			'resize'         => [$resize, Orientation::None],
			'resize, exif 6' => [$resize, Orientation::Rotate90],
		];
	}

	#[DataProvider('processMemoryProvider')]
	#[RunInSeparateProcess]
	public function testProcessMemory(array $options, Orientation $orientation): void
	{
		$jpeg   = static::jpeg(6000, 4000, $orientation);
		$source = $this->file('large.jpg', $jpeg);
		$file   = $this->file('thumb.jpg');

		$peak = $this->peak($source, $file, $options);

		// 24 MP decode to 96 MB
		$this->assertLessThan(110_000_000, $peak);
	}

	#[RunInSeparateProcess]
	public function testProcessMemoryGif(): void
	{
		$gif    = static::grey(6000, 300, 'imagegif');
		$source = $this->file('wide.gif', $gif);
		$file   = $this->file('thumb.gif');

		$peak = $this->peak($source, $file, ['width' => 800]);

		$this->assertLessThan(3_000_000, $peak);
	}

	#[RunInSeparateProcess]
	public function testProcessMemoryProfile(): void
	{
		$p3     = file_get_contents(static::FIXTURES . '/p3.icc');
		$length = pack('N', strlen($p3) + 1_000_000);
		$icc    = substr_replace($p3 . str_repeat("\0", 1_000_000), $length, 0, 4);
		$jpeg   = static::jpeg(6000, 4000, Orientation::None);
		$jpeg   = Icc::embed($jpeg, Format::Jpeg, $icc);
		$source = $this->file('large.jpg', $jpeg);
		$file   = $this->file('thumb.jpg');

		$peak   = $this->peak($source, $file, ['width' => 800]);
		$output = new Source($file);

		$this->assertLessThan(110_000_000, $peak);
		$this->assertSame($icc, $output->icc());
	}

	public function testProcessMetadataAvif(): void
	{
		$file = $this->file('thumb.avif');

		$this->process('metadata.jpg', $file, static::OPTIONS);

		$output = new Source($file);

		$this->assertSame('', $output->icc());
	}

	public static function processPngWarningProvider(): array
	{
		$p3   = file_get_contents(static::FIXTURES . '/p3.icc');
		$data = "icc\0\0" . gzcompress($p3);

		return [
			'rgb profile, gray image' => [0, $data],
			'extra compressed data'   => [2, $data . str_repeat("\0", 2000)],
		];
	}

	#[DataProvider('processPngWarningProvider')]
	public function testProcessPngWarning(int $type, string $data): void
	{
		$png      = self::iccp($type, $data);
		$source   = $this->file('source.png', $png);
		$file     = $this->file('thumb.png');
		$darkroom = new Darkroom([$this->driver]);

		$darkroom->process($source, $file, ['width' => 10]);

		$p3     = file_get_contents(static::FIXTURES . '/p3.icc');
		$output = new Source($file);
		$image  = imagecreatefrompng($file);

		$this->assertSame($p3, $output->icc());
		$this->assertSame(10, imagesx($image));
	}

	public function testProcessProfileGray(): void
	{
		$p3       = file_get_contents(static::FIXTURES . '/p3.icc');
		$icc      = substr_replace($p3, 'GRAY', 16, 4);
		$jpeg     = static::grey(30, 20, 'imagejpeg');
		$jpeg     = Icc::embed($jpeg, Format::Jpeg, $icc);
		$source   = $this->file('gray.jpg', $jpeg);
		$file     = $this->file('thumb.jpg');
		$darkroom = new Darkroom([$this->driver]);

		$darkroom->process($source, $file, ['width' => 10]);

		$input  = new Source($source);
		$output = new Source($file);

		$this->assertSame($icc, $input->icc());
		$this->assertSame('', $output->icc());
	}

	#[DataProvider('processSamplingProvider')]
	public function testProcessSampling(string $fixture): void
	{
		if ($this->subsamples() === false) {
			$this->markTestSkipped('GD keeps chroma whole from quality 90 on');
		}

		parent::testProcessSampling($fixture);
	}

	public function testProcessUnwritable(): void
	{
		$source = new Source(static::FIXTURES . '/png/plain.png');
		$options  = Options::from(['width' => 10]);
		$plan   = Plan::for($source, $options, driver: $this->driver);
		$file   = $this->file('directory');

		mkdir($file);

		$this->expectException(ProcessException::class);
		$this->expectExceptionMessage('GD failed on ' . $source->file);

		$this->driver->process($plan, $file);
	}
}
