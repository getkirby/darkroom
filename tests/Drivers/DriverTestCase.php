<?php

namespace Darkroom\Tests\Drivers;

use Darkroom\Darkroom;
use Darkroom\Driver;
use Darkroom\Drivers\Gd;
use Darkroom\Drivers\Imagick as ImagickDriver;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Exception\UnsupportedException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Rect;
use Darkroom\Size;
use Darkroom\Source;
use Darkroom\Tests\Required;
use Darkroom\Tests\TestCase;
use GdImage;
use Imagick;
use PHPUnit\Framework\Attributes\DataProvider;

abstract class DriverTestCase extends TestCase
{
	use Required;

	protected const float CMYK = self::DIFFERENCE;
	protected const float DIFFERENCE = 0.1;
	protected const string FIXTURES = __DIR__ . '/../fixtures';
	protected const bool ICC = true;
	protected const float MATCH = 0.05;
	protected const array OPTIONS = [
		'width'  => 48,
		'height' => 48
	];
	protected const string REFERENCES = __DIR__ . '/../references';

	protected Driver $driver;

	protected function assertReference(
		string $name,
		string $file,
		float $limit = self::DIFFERENCE
	): void {
		$reference = static::REFERENCES . '/' . $name . '.png';

		$this->assertFileExists($reference, 'No reference for ' . $name);

		$expected = $this->image($reference);
		$actual   = $this->image($file);
		$size     = imagesx($actual) . '×' . imagesy($actual);

		$this->assertSame(imagesx($expected) . '×' . imagesy($expected), $size);

		$difference = static::difference($expected, $actual);

		$this->assertLessThanOrEqual($limit, $difference, 'Differs from ' . $name);
	}

	protected function blocks(string $name, int ...$colors): string
	{
		$file  = $this->file($name);
		$image = imagecreatetruecolor(16 * count($colors), 16);

		imagealphablending($image, false);
		imagesavealpha($image, max($colors) > 0xFFFFFF);

		foreach ($colors as $index => $color) {
			imagefilledrectangle($image, 16 * $index, 0, 16 * $index + 15, 15, $color);
		}

		imagepng($image, $file);

		return $file;
	}

	protected static function cicp(string $file): array|null
	{
		$data = file_get_contents($file);
		$box  = strpos($data, 'colrnclx');

		if ($box === false) {
			return null;
		}

		return array_values(unpack('n3', $data, $box + 8));
	}

	protected static function depth(string $file): array
	{
		$data = file_get_contents($file);
		$box  = strpos($data, 'pixi');

		if ($box === false) {
			return [];
		}

		$channels = unpack('C', $data, $box + 8)[1];

		return array_values(unpack('C' . $channels, $data, $box + 9));
	}

	protected function detail(string $file): float
	{
		$image  = $this->image($file);
		$width  = imagesx($image);
		$height = imagesy($image);
		$sum    = 0;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 1; $x < $width; $x++) {
				$a = imagecolorat($image, $x - 1, $y);
				$b = imagecolorat($image, $x, $y);

				$sum += abs(($a >> 16 & 0xFF) - ($b >> 16 & 0xFF));
				$sum += abs(($a >> 8 & 0xFF) - ($b >> 8 & 0xFF));
				$sum += abs(($a & 0xFF) - ($b & 0xFF));
			}
		}

		return $sum / (($width - 1) * $height * 3);
	}

	protected static function difference(GdImage $expected, GdImage $actual): float
	{
		$width  = imagesx($expected);
		$height = imagesy($expected);
		$sum    = 0.0;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$a = static::flatten(imagecolorat($expected, $x, $y));
				$b = static::flatten(imagecolorat($actual, $x, $y));

				$sum += ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2;
			}
		}

		return sqrt($sum / ($width * $height * 3)) / 255;
	}

	abstract protected function driver(): Driver;

	protected function first(string $file): string
	{
		$this->readable($file);

		$image = new Imagick($file . '[0]');
		$image->setImageFormat('png');

		return $image->getImageBlob();
	}

	protected static function flatten(int $color): array
	{
		$opacity = 1 - ($color >> 24 & 0x7F) / 127;
		$back    = 255 * (1 - $opacity);

		return [
			($color >> 16 & 0xFF) * $opacity + $back,
			($color >> 8 & 0xFF) * $opacity,
			($color & 0xFF) * $opacity + $back,
		];
	}

	protected function frames(string $file): int
	{
		$this->readable($file);

		$image = new Imagick();
		$image->pingImage($file);

		return $image->getNumberImages();
	}

	protected static function grey(
		int $width,
		int $height,
		string $encoder
	): string {
		$image  = imagecreate($width, $height);
		$stream = fopen('php://memory', 'w+b');

		imagecolorallocate($image, 128, 128, 128);
		$encoder($image, $stream);
		rewind($stream);

		return stream_get_contents($stream);
	}

	protected function hdr(): bool
	{
		return true;
	}

	protected function icc(string $file): string
	{
		$this->readable($file);

		$image    = new Imagick($file);
		$profiles = $image->getImageProfiles('icc');

		return $profiles['icc'] ?? '';
	}

	protected function image(string $file): GdImage
	{
		$data   = file_get_contents($file);
		$format = Format::detect($data);
		$source = new Source($file);
		$gd     = new Gd();

		if ($format !== null && $gd->canRead($format) === false) {
			$this->skip('GD can\'t compare ' . $format->extension(), $gd, $format);
		}

		if ($gd->canRead($source) === false) {
			$data = $this->first($file);
		}

		$image  = imagecreatefromstring($data);
		$canvas = $source->canvas();

		imagepalettetotruecolor($image);

		if ($canvas === null) {
			return $image;
		}

		$placed = imagecreatetruecolor($canvas->width, $canvas->height);
		$width  = imagesx($image);
		$height = imagesy($image);

		imagealphablending($placed, false);
		imagefill($placed, 0, 0, 0x7F000000);
		imagecopy($placed, $image, -$canvas->x, -$canvas->y, 0, 0, $width, $height);

		return $placed;
	}

	protected static function interlaced(string $file): bool
	{
		$data   = file_get_contents($file);
		$format = Format::detect($data);

		return match ($format) {
			Format::Jpeg => str_contains($data, "\xFF\xC2"),
			Format::Png  => $data[28] === "\x01",
		};
	}

	protected static function jpeg(
		int $width,
		int $height,
		Orientation $orientation
	): string {
		$jpeg = static::grey($width, $height, 'imagejpeg');
		$app1 = static::app1($orientation->value);

		return substr_replace($jpeg, $app1, 2, 0);
	}

	protected function process(
		string $fixture,
		string $file,
		array $options,
		array $defaults = []
	): Plan {
		$source = new Source(static::FIXTURES . '/' . $fixture);
		$input  = $source->format();
		$output = Format::from(pathinfo($file, PATHINFO_EXTENSION));

		if ($this->driver->canRead($input) === false) {
			$this->skip('Can\'t read ' . $input->extension(), $this->driver, $input);
		}

		if ($this->driver->canRead($source) === false) {
			$this->markTestSkipped('Can\'t read ' . $fixture);
		}

		if ($this->driver->canWrite($output) === false) {
			$this->skip('Can\'t write ' . $output->extension(), $this->driver, $output);
		}

		$darkroom = new Darkroom([$this->driver], $defaults);
		$hash     = md5_file($source->file);

		$plan = $darkroom->process($source->file, $file, $options);

		$this->assertSame($hash, md5_file($source->file), 'Changed ' . $fixture);

		return $plan;
	}

	protected static function profile(string $file): bool
	{
		$data = file_get_contents($file);

		return
			str_contains($data, 'ICC_PROFILE') === true ||
			str_contains($data, 'iCCP') === true ||
			str_contains($data, 'ICCP') === true;
	}

	protected function readable(string $file): void
	{
		$data   = file_get_contents($file);
		$format = Format::detect($data);
		$driver = new ImagickDriver();

		if ($driver->isAvailable() === false) {
			$this->skip('Reading the output needs Imagick', $driver);
		}

		if ($format !== null && $driver->canRead($format) === false) {
			$this->skip('Imagick can\'t read ' . $format->extension(), $driver, $format);
		}
	}

	protected static function rgb(int $color): array
	{
		return [$color >> 16 & 0xFF, $color >> 8 & 0xFF, $color & 0xFF];
	}

	protected static function sampling(string $file): string
	{
		$data   = file_get_contents($file);
		$offset = 2;

		while (in_array($data[$offset + 1], ["\xC0", "\xC1", "\xC2"], true) === false) {
			$offset += 2 + unpack('n', $data, $offset + 2)[1];
		}

		$components = ord($data[$offset + 9]);
		$factors    = [];

		for ($component = 0; $component < $components; $component++) {
			$byte      = ord($data[$offset + 11 + 3 * $component]);
			$factors[] = ($byte >> 4) . 'x' . ($byte & 0x0F);
		}

		return implode(',', $factors);
	}

	protected function setUp(): void
	{
		$this->driver = $this->driver();

		if ($this->driver->isAvailable() === false) {
			$this->skip('Driver not available', $this->driver);
		}
	}

	protected function strip(string $file): string
	{
		$this->readable($file);

		$image  = new Imagick($file);
		$frames = $image->coalesceImages();
		$strip  = $frames->appendImages(true);
		$png    = $file . '.png';

		$strip->setImagePage(0, 0, 0, 0);
		$strip->writeImage('png:' . $png);

		return $png;
	}

	public static function processAlphaProvider(): array
	{
		return [
			'png to png'   => ['png/alpha.png', 'png'],
			'png to webp'  => ['png/alpha.png', 'webp'],
			'png to avif'  => ['png/alpha.png', 'avif'],
			'webp to png'  => ['webp/alpha.webp', 'png'],
			'webp to webp' => ['webp/alpha.webp', 'webp'],
		];
	}

	#[DataProvider('processAlphaProvider')]
	public function testProcessAlpha(string $fixture, string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process($fixture, $file, static::OPTIONS);

		$this->assertReference($fixture, $file);
	}

	/**
	 * `magick <fixture> -coalesce -resize '48x48>' -append`
	 * `magick <fixture> -coalesce -resize 30x30^ -gravity center -extent 30x30 -append`
	 */
	public static function processAnimationProvider(): array
	{
		$crop = ['width' => 30, 'height' => 30, 'crop' => true];

		return [
			'gif to gif'         => ['gif/partial.gif', 'gif', static::OPTIONS, 'gif/partial.gif'],
			'webp to webp, crop' => ['webp/partial.webp', 'webp', $crop, 'crop/partial'],
			'webp to gif'        => ['webp/partial.webp', 'gif', static::OPTIONS, 'webp/partial.webp'],
		];
	}

	#[DataProvider('processAnimationProvider')]
	public function testProcessAnimation(
		string $fixture,
		string $extension,
		array $options,
		string $reference
	): void {
		$file = $this->file('thumb.' . $extension);

		$plan = $this->process($fixture, $file, $options);

		if ($plan->frames === false) {
			$this->markTestSkipped('Keeps no frames of ' . $fixture);
		}

		$strip = $this->strip($file);

		$this->assertReference($reference, $strip, static::MATCH);
	}

	public function testProcessBroken(): void
	{
		$png      = static::bare(30, 20);
		$source   = $this->file('broken.png', $png);
		$file     = $this->file('thumb.png');
		$darkroom = new Darkroom([$this->driver]);

		$this->expectException(ProcessException::class);

		$darkroom->process($source, $file, ['width' => 10]);
	}

	public static function processCanvasProvider(): array
	{
		return [
			'gif frame'     => ['gif/optimized.gif'],
			'avif aperture' => ['heif/clap.avif'],
		];
	}

	#[DataProvider('processCanvasProvider')]
	public function testProcessCanvas(string $fixture): void
	{
		$file = $this->file('thumb.png');

		$this->process($fixture, $file, static::OPTIONS);

		$this->assertReference($fixture, $file);
	}

	public static function processCappedProvider(): array
	{
		return [
			'both sides'  => ['png/plain.png', [60, 40], [30, 20]],
			'one side'    => ['png/plain.png', [100, 30], [30, 9]],
			'orientation' => ['orientation/Landscape_6.jpg', [360, 360], [120, 120]],
		];
	}

	#[DataProvider('processCappedProvider')]
	public function testProcessCapped(
		string $fixture,
		array $requested,
		array $size
	): void {
		$file     = $this->file('thumb.png');
		$expected = $this->file('expected.png');
		$options    = ['width' => $requested[0], 'height' => $requested[1], 'crop' => true];
		$fit      = ['width' => $size[0], 'height' => $size[1], 'crop' => true];

		$this->process($fixture, $file, $options);
		$this->process($fixture, $expected, $fit);

		$output = new Source($file);

		$this->assertEquals(new Size(...$size), $output->size());
		$this->assertFileEquals($expected, $file);
	}

	public static function processCicpProvider(): array
	{
		return [
			'avif to png'  => ['heif/plain.avif', 'png'],
			'avif to jpeg' => ['heif/plain.avif', 'jpg'],
			'avif to webp' => ['heif/plain.avif', 'webp'],
			'heic to png'  => ['heif/plain.heic', 'png'],
			'heic to jpeg' => ['heif/plain.heic', 'jpg'],
			'heic to webp' => ['heif/plain.heic', 'webp'],
		];
	}

	#[DataProvider('processCicpProvider')]
	public function testProcessCicp(string $fixture, string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process($fixture, $file, static::OPTIONS);

		$data = file_get_contents($file);

		foreach (['cicp', 'tEXt', 'zTXt', 'iTXt'] as $marker) {
			$this->assertStringNotContainsString($marker, $data);
		}
	}

	public function testProcessCmyk(): void
	{
		$file = $this->file('thumb.jpg');

		$this->process('cmyk.jpg', $file, static::OPTIONS);

		$info = getimagesize($file);

		$this->assertSame(3, $info['channels']);
		$this->assertReference('cmyk.jpg', $file, static::CMYK);
	}

	public static function processCmykFormatProvider(): array
	{
		return [
			'png'  => ['png'],
			'webp' => ['webp'],
			'avif' => ['avif'],
			'jxl'  => ['jxl'],
		];
	}

	#[DataProvider('processCmykFormatProvider')]
	public function testProcessCmykFormat(string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process('cmyk.jpg', $file, static::OPTIONS);

		$png = $this->strip($file);

		$this->assertReference('cmyk.jpg', $png, static::CMYK);
	}

	/**
	 * `magick -size 30x20 -delay 10 xc:'#c04040' -delay 50
	 * xc:'#40c040' -delay 100 xc:'#4040c0' -loop 2 delays.<extension>`
	 */
	public static function processDelaysProvider(): array
	{
		$cases = [];

		foreach (['gif/delays.gif', 'webp/delays.webp'] as $fixture) {
			foreach (['gif', 'webp', 'jxl'] as $extension) {
				$name = pathinfo($fixture, PATHINFO_EXTENSION) . ' to ' . $extension;

				$cases[$name] = [$fixture, $extension];
			}
		}

		return $cases;
	}

	#[DataProvider('processDelaysProvider')]
	public function testProcessDelays(string $fixture, string $extension): void
	{
		$source = static::FIXTURES . '/' . $fixture;
		$file   = $this->file('thumb.' . $extension);

		$this->process($fixture, $file, static::OPTIONS);

		$output = new Source($file);

		if ($output->isAnimated() === false) {
			$this->markTestSkipped('Keeps no frames of ' . $fixture);
		}

		$expected = $this->timing($source);
		$actual   = $this->timing($file);

		$this->assertSame($expected, $actual);
	}

	public function testProcessDepth(): void
	{
		$file = $this->file('thumb.png');

		$this->process('png/16-bit.png', $file, static::OPTIONS);

		$info = getimagesize($file);

		$this->assertSame(8, $info['bits']);
	}

	/**
	 * `magick <fixture> -resize '48x32!' -grayscale Rec601Luma -blur 0x<sigma> -sharpen 0x<amount / 100>`
	 */
	public static function processFilterProvider(): array
	{
		return [
			'none'      => ['orientation/Landscape_1.jpg', []],
			'grayscale' => ['filter/grayscale', ['grayscale' => true]],
			'blur 1'    => ['filter/blur-1', ['blur' => 1]],
			'blur 10'   => ['filter/blur-10', ['blur' => 10]],
			'sharpen'   => ['filter/sharpen', ['sharpen' => 100]],
			'all'       => [
				'filter/all',
				['grayscale' => true, 'blur' => 2, 'sharpen' => 100]
			],
		];
	}

	#[DataProvider('processFilterProvider')]
	public function testProcessFilter(string $reference, array $filters): void
	{
		$fixture = 'orientation/Landscape_1.jpg';
		$plain   = $this->file('plain.png');
		$file    = $this->file('thumb.png');
		$options   = ['width' => 48, ...$filters];

		$this->process($fixture, $plain, ['width' => 48]);
		$this->process($fixture, $file, $options);

		$expected = $this->detail(static::REFERENCES . '/' . $reference . '.png');
		$original = $this->detail(static::REFERENCES . '/' . $fixture . '.png');
		$actual   = $this->detail($file);
		$own      = $this->detail($plain);
		$factor   = $expected / $original;

		$this->assertReference($reference, $file, static::MATCH);
		$this->assertEqualsWithDelta($factor, $actual / $own, $factor / 5, 'Detail differs');
	}

	public function testProcessFlatten(): void
	{
		$file     = $this->file('thumb.jpg');
		$darkroom = new Darkroom([$this->driver]);

		// half opaque red, and transparent green
		$source = $this->blocks('alpha.png', 0x3FFF0000, 0x7F00FF00);

		$darkroom->process($source, $file, ['width' => 32]);

		$options = $this->image($file);
		$red   = imagecolorat($options, 8, 8);
		$green = imagecolorat($options, 24, 8);

		$this->assertEqualsWithDelta([255, 128, 128], static::rgb($red), 3);
		$this->assertEqualsWithDelta([255, 255, 255], static::rgb($green), 3);
	}

	/**
	 * `magick <fixture> -auto-orient -resize 24x20^ -gravity <crop> -extent 24x20`
	 * `magick <fixture> -auto-orient -crop 144x120+27+0 -resize 24x20`
	 */
	public static function processFocusProvider(): array
	{
		return [
			'top left'            => ['Landscape_1.jpg', 'top left', 'landscape-top-left'],
			'bottom right'        => ['Landscape_1.jpg', 'bottom right', 'landscape-bottom-right'],
			'exif 6 top left'     => ['Landscape_6.jpg', 'top left', 'exif-6-top-left'],
			'exif 6 bottom right' => ['Landscape_6.jpg', 'bottom right', 'exif-6-bottom-right'],
			'exif 8 top'          => ['Portrait_8.jpg', 'top', 'portrait-top'],
			'exif 8 bottom'       => ['Portrait_8.jpg', 'bottom', 'portrait-bottom'],
			'focus'               => ['Landscape_1.jpg', '55% 50%', 'focus'],
		];
	}

	#[DataProvider('processFocusProvider')]
	public function testProcessFocus(
		string $fixture,
		string $crop,
		string $reference
	): void {
		$file  = $this->file('thumb.png');
		$options = ['width' => 24, 'height' => 20, 'crop' => $crop];

		$this->process('orientation/' . $fixture, $file, $options);

		$this->assertReference('crop/' . $reference, $file);
	}

	public static function processFormatProvider(): array
	{
		$formats = [];

		foreach (Format::cases() as $format) {
			$formats[$format->extension()] = [$format];
		}

		return $formats;
	}

	#[DataProvider('processFormatProvider')]
	public function testProcessFormat(Format $format): void
	{
		$file = $this->file('thumb.' . $format->extension());

		$plan = $this->process('orientation/Landscape_1.jpg', $file, static::OPTIONS);

		$output = new Source($file);
		$info   = getimagesize($file);

		$this->assertSame($format, $plan->format);
		$this->assertSame($format, $output->format());
		$this->assertEquals($plan->size(), $output->size());

		if ($info !== false) {
			$this->assertSame($format, Format::from($info['mime']));
		}
	}

	public static function processFramesProvider(): array
	{
		return [
			'gif'  => ['gif/animated.gif'],
			'webp' => ['webp/animated.webp'],
			'jxl'  => ['jxl/animated.jxl'],
		];
	}

	#[DataProvider('processFramesProvider')]
	public function testProcessFrames(string $fixture): void
	{
		$source    = static::FIXTURES . '/' . $fixture;
		$extension = pathinfo($fixture, PATHINFO_EXTENSION);
		$file      = $this->file('thumb.' . $extension);

		$plan = $this->process($fixture, $file, static::OPTIONS);

		$output = new Source($file);

		$this->assertSame($plan->frames, $output->isAnimated());

		if ($plan->frames === true) {
			$expected = $this->frames($source);
			$actual   = $this->frames($file);

			$this->assertSame($expected, $actual);
		}
	}

	public function testProcessGifAlpha(): void
	{
		$file     = $this->file('thumb.gif');
		$darkroom = new Darkroom([$this->driver]);

		// a quarter and three quarters opaque
		$source = $this->blocks('alpha.png', 0x5FC04080, 0x20C04080);

		$darkroom->process($source, $file, ['width' => 32]);

		$options  = $this->image($file);
		$alphas = array_map(fn (int $x): int => imagecolorat($options, $x, 8) >> 24, [8, 24]);

		$this->assertSame([127, 0], $alphas);
	}

	public function testProcessGrayscale(): void
	{
		$source   = $this->blocks('primaries.png', 0xFF0000, 0x00FF00, 0x0000FF);
		$file     = $this->file('thumb.png');
		$darkroom = new Darkroom([$this->driver]);

		$darkroom->process($source, $file, ['width' => 48, 'grayscale' => true]);

		$options = $this->image($file);
		$grays = array_map(fn (int $x): int => imagecolorat($options, $x, 8) & 0xFF, [8, 24, 40]);

		// Rec. 601 luma: 0.299 R + 0.587 G + 0.114 B
		$this->assertEqualsWithDelta([76, 149, 29], $grays, 1);
	}

	/**
	 * `magick -size 30x20 -delay 100 xc:'#c0392b' xc:'#2471a3' luma.gif`
	 */
	public function testProcessGrayscaleGif(): void
	{
		$file = $this->file('thumb.gif');

		$plan = $this->process('gif/luma.gif', $file, ['width' => 30, 'grayscale' => true]);

		if ($plan->frames === false) {
			$this->markTestSkipped('Keeps no frames of gif/luma.gif');
		}

		$png      = $this->strip($file);
		$strip    = $this->image($png);
		$frames   = intdiv(imagesy($strip), 20);
		$colors   = [];
		[$delays] = $this->timing($file);

		for ($frame = 0; $frame < $frames; $frame++) {
			$color    = imagecolorat($strip, 15, 20 * $frame + 10);
			$colors[] = static::rgb($color);
		}

		$this->assertEqualsWithDelta(array_fill(0, $frames, [96, 96, 96]), $colors, 1);
		$this->assertSame(2000, array_sum($delays));
	}

	public static function processGrayscaleHeifProvider(): array
	{
		return [
			'avif to avif' => ['heif/plain.avif', 'avif'],
			'avif to heic' => ['heif/plain.avif', 'heic'],
			'heic to avif' => ['heif/plain.heic', 'avif'],
			'heic to heic' => ['heif/plain.heic', 'heic'],
		];
	}

	#[DataProvider('processGrayscaleHeifProvider')]
	public function testProcessGrayscaleHeif(string $fixture, string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$plan = $this->process($fixture, $file, [...static::OPTIONS, 'grayscale' => true]);

		$output = new Source($file);

		$this->assertEquals($plan->size(), $output->size());
	}

	public static function processHdrProvider(): array
	{
		return [
			'avif' => ['avif'],
			'heic' => ['heic'],
		];
	}

	/**
	 * `magick -size 30x20 gradient:'#c08040-#4080c0'
	 * -depth 10 -set heic:cicp 9/16/9/1 hdr.avif`
	 */
	#[DataProvider('processHdrProvider')]
	public function testProcessHdr(string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process('heif/hdr.avif', $file, static::OPTIONS);

		// BT.2020 primaries and matrix, PQ transfer
		$cicp = static::cicp($file);

		$this->assertSame($this->hdr(), $cicp === [9, 16, 9]);
	}

	#[DataProvider('processHdrProvider')]
	public function testProcessHdrDepth(string $extension): void
	{
		if ($this->hdr() === false) {
			$this->markTestSkipped('Keeps no HDR');
		}

		$file = $this->file('thumb.' . $extension);

		$this->process('heif/hdr.avif', $file, static::OPTIONS);

		$this->assertSame([10, 10, 10], static::depth($file));
	}

	public static function processHdrToneProvider(): array
	{
		return [
			'jpeg' => ['jpg', false],
			'webp' => ['webp', false],
			'avif' => ['avif', true],
			'heic' => ['heic', true],
		];
	}

	#[DataProvider('processHdrToneProvider')]
	public function testProcessHdrTone(string $extension, bool $hdr): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process('heif/hdr.avif', $file, static::OPTIONS);

		if ($hdr === true && $this->hdr() === true) {
			$this->markTestSkipped('Keeps HDR in ' . $extension);
		}

		$png = $this->strip($file);

		$this->assertReference('heif/hdr.avif', $png);
	}

	public static function processInterlaceProvider(): array
	{
		return [
			'progressive jpeg' => ['jpg', true],
			'baseline jpeg'    => ['jpg', false],
			'interlaced png'   => ['png', true],
			'plain png'        => ['png', false],
		];
	}

	#[DataProvider('processInterlaceProvider')]
	public function testProcessInterlace(string $extension, bool $interlace): void
	{
		$file  = $this->file('thumb.' . $extension);
		$options = [...static::OPTIONS, 'interlace' => $interlace];

		$this->process('orientation/Landscape_1.jpg', $file, $options);

		$this->assertSame($interlace, static::interlaced($file));
	}

	public static function processMatchProvider(): array
	{
		$crop    = ['width' => 24, 'height' => 20, 'crop' => 'bottom right'];
		$filters = ['width' => 48, 'grayscale' => true, 'blur' => 2, 'sharpen' => 100];

		return [
			'crop'        => ['orientation/Landscape_6.jpg', $crop],
			'orientation' => ['orientation/Portrait_5.jpg', static::OPTIONS],
			'filters'     => ['orientation/Landscape_1.jpg', $filters],
		];
	}

	#[DataProvider('processMatchProvider')]
	public function testProcessMatch(string $fixture, array $options): void
	{
		$source = new Source(static::FIXTURES . '/' . $fixture);
		$file   = $this->file('thumb.png');
		$all    = new Darkroom();
		$others = 0;

		$this->process($fixture, $file, $options);

		$image = $this->image($file);

		foreach ($all->drivers as $name) {
			$darkroom = new Darkroom([$name]);
			$driver   = $darkroom->driver($source, Format::Png);

			if ($driver === null || $driver::class === $this->driver::class) {
				continue;
			}

			$other = $this->file($name . '.png');

			$darkroom->process($source->file, $other, $options);

			$theirs     = $this->image($other);
			$difference = static::difference($image, $theirs);

			$this->assertLessThanOrEqual(static::MATCH, $difference, 'Differs from ' . $name);
			$others++;
		}

		if ($others === 0) {
			$this->markTestSkipped('No other driver');
		}
	}

	public static function processMetadataProvider(): array
	{
		return [
			'jpeg' => ['jpg'],
			'png'  => ['png'],
			'webp' => ['webp'],
			'avif' => ['avif'],
			'heic' => ['heic'],
		];
	}

	/**
	 * `exiftool -o metadata.jpg -GPSLatitude=52.5
	 * -GPSLatitudeRef=N -GPSLongitude=13.4 -GPSLongitudeRef=E
	 * -XMP-dc:Title=Darkroom -IPTC:Caption-Abstract=Darkroom icc.jpg`
	 */
	#[DataProvider('processMetadataProvider')]
	public function testProcessMetadata(string $extension): void
	{
		$source = static::FIXTURES . '/metadata.jpg';
		$file   = $this->file('thumb.' . $extension);

		$this->process('metadata.jpg', $file, static::OPTIONS);

		$data = file_get_contents($file);

		$markers = ['exif', 'xmp', 'iptc', '8bim', 'photoshop'];
		$found   = array_filter(
			$markers,
			fn (string $marker): bool => stripos($data, $marker) !== false
		);

		$this->assertSame([], array_values($found));

		$expected = static::ICC === true ? $this->icc($source) : '';
		$actual   = $this->icc($file);

		$this->assertSame(md5($expected), md5($actual));
	}

	public static function processOrientationProvider(): array
	{
		$fixtures = [
			...glob(static::FIXTURES . '/orientation/*_[1-8].jpg'),
			static::FIXTURES . '/webp/exif-6.webp',
			static::FIXTURES . '/png/exif-6.png',
			...glob(static::FIXTURES . '/heif/i[mr]*.avif'),
			static::FIXTURES . '/heif/exif.avif',
			static::FIXTURES . '/heif/exif-irot-2.avif',
			static::FIXTURES . '/heif/exif.heic',
			static::FIXTURES . '/jxl/exif-6.jxl',
		];

		$cases = [];

		foreach ($fixtures as $fixture) {
			$name         = substr($fixture, strlen(static::FIXTURES) + 1);
			$cases[$name] = [$name];
		}

		return $cases;
	}

	#[DataProvider('processOrientationProvider')]
	public function testProcessOrientation(string $fixture): void
	{
		$file = $this->file('thumb.png');

		$this->process($fixture, $file, static::OPTIONS);

		$this->assertReference($fixture, $file);
	}

	public static function processOrientationTagProvider(): array
	{
		return [
			'jpeg'         => ['orientation/Landscape_6.jpg', 'jpg'],
			'webp'         => ['webp/exif-6.webp', 'webp'],
			'png'          => ['png/exif-6.png', 'png'],
			'avif'         => ['heif/irot-1.avif', 'avif'],
			'heic'         => ['heif/exif.heic', 'heic'],
			'jxl'          => ['jxl/exif-6.jxl', 'jxl'],
			'jpeg to webp' => ['orientation/Landscape_6.jpg', 'webp'],
			'jpeg to png'  => ['orientation/Landscape_6.jpg', 'png'],
			'jpeg to avif' => ['orientation/Landscape_6.jpg', 'avif'],
			'jpeg to heic' => ['orientation/Landscape_6.jpg', 'heic'],
			'jpeg to jxl'  => ['orientation/Landscape_6.jpg', 'jxl'],
		];
	}

	#[DataProvider('processOrientationTagProvider')]
	public function testProcessOrientationTag(string $fixture, string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$plan = $this->process($fixture, $file, static::OPTIONS);

		$output = new Source($file);

		$this->assertSame(Orientation::None, $output->orientation());
		$this->assertEquals($plan->size(), $output->size());
	}

	public static function processProfileProvider(): array
	{
		return [
			'jpeg'          => ['jpg', true],
			'jpeg stripped' => ['jpg', false],
			'png'           => ['png', true],
			'png stripped'  => ['png', false],
			'webp'          => ['webp', true],
			'webp stripped' => ['webp', false],
		];
	}

	#[DataProvider('processProfileProvider')]
	public function testProcessProfile(string $extension, bool $icc): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process('icc.jpg', $file, static::OPTIONS, ['icc' => $icc]);

		$this->assertSame($icc === true && static::ICC === true, static::profile($file));
	}

	public static function processQualityProvider(): array
	{
		return [
			'avif' => ['avif'],
			'jpeg' => ['jpg'],
			'webp' => ['webp'],
		];
	}

	#[DataProvider('processQualityProvider')]
	public function testProcessQuality(string $extension): void
	{
		$fixture = 'orientation/Landscape_1.jpg';
		$low     = $this->file('low.' . $extension);
		$high    = $this->file('high.' . $extension);

		$this->process($fixture, $low, [...static::OPTIONS, 'quality' => 20]);
		$this->process($fixture, $high, [...static::OPTIONS, 'quality' => 90]);

		$this->assertLessThan(filesize($high), filesize($low));
	}

	/**
	 * `magick orientation/Landscape_1.jpg -strip -sampling-factor 1x1 -quality 90 sampling-444.jpg`
	 * `magick orientation/Landscape_1.jpg -strip -sampling-factor 2x1 -quality 90 sampling-422.jpg`
	 */
	public static function processSamplingProvider(): array
	{
		return [
			'4:4:4' => ['sampling-444.jpg'],
			'4:2:2' => ['sampling-422.jpg'],
			'4:2:0' => ['orientation/Landscape_1.jpg'],
		];
	}

	#[DataProvider('processSamplingProvider')]
	public function testProcessSampling(string $fixture): void
	{
		$file  = $this->file('thumb.jpg');
		$options = [...static::OPTIONS, 'quality' => 90];

		$this->process($fixture, $file, $options);

		$this->assertSame('2x2,1x1,1x1', static::sampling($file));
	}

	public function testProcessScreen(): void
	{
		$screen = new Size(2, 2);
		$frame  = new Rect(0, 0, 200, 200);
		$gif    = static::framed($screen, $frame);
		$source = $this->file('frame.gif', $gif);
		$file   = $this->file('thumb.png');

		// by class name, so that the driver gets the limit
		$darkroom = new Darkroom([$this->driver::class], settings: ['limit' => 10_000]);

		try {
			$darkroom->process($source, $file, ['width' => 2]);
		} catch (LimitException | ProcessException | UnsupportedException $exception) {
			$this->assertFileDoesNotExist($file, $exception->getMessage());

			return;
		}

		$this->fail('Decoded a frame of 40,000 pixels within a limit of 10,000');
	}

	public static function processSizeProvider(): array
	{
		return [
			'width'         => ['orientation/Landscape_1.jpg', ['width' => 50]],
			'height'        => ['orientation/Landscape_1.jpg', ['height' => 50]],
			'box'           => ['orientation/Portrait_6.jpg', ['width' => 50, 'height' => 50]],
			'not enlarged'  => ['png/plain.png', ['width' => 100]],
			'crop'          => [
				'orientation/Landscape_6.jpg',
				['width' => 50, 'height' => 50, 'crop' => true]
			],
			'crop capped'   => [
				'png/plain.png',
				['width' => 100, 'height' => 30, 'crop' => true]
			],
			'odd'           => ['webp/odd-lossy.webp', ['width' => 17]],
			'gif'           => ['gif/still.gif', ['width' => 17]],
			'avif'          => ['heif/plain.avif', ['width' => 17]],
			'heic'          => ['heif/odd.heic', ['width' => 17]],
			'heic grid'     => ['heif/grid.heic', ['width' => 17]],
			'jxl'           => ['jxl/odd.jxl', ['width' => 17]],
		];
	}

	#[DataProvider('processSizeProvider')]
	public function testProcessSize(string $fixture, array $options): void
	{
		$file = $this->file('thumb.png');

		$plan = $this->process($fixture, $file, $options);

		$output = new Source($file);

		$this->assertEquals($plan->size(), $output->size());
	}

	/**
	 * `magick -define heic:primary-image=true
	 * '<fixture>[0]' -resize '48x48>'`
	 */
	public static function processStillProvider(): array
	{
		return [
			'apng' => ['png/animated.png'],
			'avif' => ['heif/animated.avif'],
			'heic' => ['heif/animated.heic'],
		];
	}

	#[DataProvider('processStillProvider')]
	public function testProcessStill(string $fixture): void
	{
		$file = $this->file('thumb.gif');

		$plan = $this->process($fixture, $file, static::OPTIONS);

		$this->assertFalse($plan->frames);
		$this->assertSame(1, $this->frames($file));
		$this->assertReference($fixture, $file);
	}

	public static function processTransparencyProvider(): array
	{
		return [
			'gif'  => ['gif/optimized.gif', 'gif'],
			'webp' => ['gif/optimized.gif', 'webp'],
		];
	}

	#[DataProvider('processTransparencyProvider')]
	public function testProcessTransparency(string $fixture, string $extension): void
	{
		$file = $this->file('thumb.' . $extension);

		$this->process($fixture, $file, static::OPTIONS);

		$this->assertReference($fixture, $file);
	}

	protected function timing(string $file): array
	{
		$this->readable($file);

		$image  = new Imagick($file);
		$delays = [];

		foreach ($image as $frame) {
			$delay    = $frame->getImageDelay() * 1000;
			$delays[] = intdiv($delay, $frame->getImageTicksPerSecond());
		}

		return [$delays, $image->getImageIterations()];
	}
}
