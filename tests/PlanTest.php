<?php

namespace Darkroom\Tests;

use Darkroom\Defaults;
use Darkroom\Exception\UnsupportedException;
use Darkroom\Format;
use Darkroom\Options;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Rect;
use Darkroom\Size;
use Darkroom\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(Plan::class)]
class PlanTest extends TestCase
{
	protected const string FIXTURES = __DIR__ . '/fixtures';

	protected function assertInside(
		Rect $rect,
		Size $size,
		string $message = ''
	): void {
		$this->assertGreaterThanOrEqual(0, $rect->x, $message);
		$this->assertGreaterThanOrEqual(0, $rect->y, $message);
		$this->assertLessThanOrEqual($size->width, $rect->x + $rect->width, $message);
		$this->assertLessThanOrEqual($size->height, $rect->y + $rect->height, $message);
	}

	protected static function deviation(Rect|Size $size, Size $ratio): float
	{
		return min(
			abs($size->width * $ratio->height / $ratio->width - $size->height),
			abs($size->height * $ratio->width / $ratio->height - $size->width)
		);
	}

	protected static function jpeg(Size $stored, Orientation $orientation): string
	{
		$app1  = self::app1($orientation->value);
		$frame = "\x08" . pack('nn', $stored->height, $stored->width) . "\x01\x01\x11\0";
		$sof   = self::segment(0xC0, $frame);

		return "\xFF\xD8" . $app1 . $sof;
	}

	protected function source(
		int $width,
		int $height,
		Orientation $orientation = Orientation::None
	): Source {
		$size   = new Size($width, $height);
		$stored = $orientation->apply($size);
		$data   = self::jpeg($stored, $orientation);
		$name   = $width . 'x' . $height . '-' . $orientation->value . '.jpg';
		$file   = $this->file($name, $data);

		return new Source($file);
	}

	public static function forCropProvider(): array
	{
		$source = [1200, 768];

		return [
			'both sides'     => [$source, [1000, 500], [1000, 500], [0, 84, 1200, 600]],
			'width only'     => [$source, [500, null], [500, 500], [216, 0, 768, 768]],
			'height only'    => [$source, [null, 500], [500, 500], [216, 0, 768, 768]],
			'larger, capped' => [$source, [2000, 1000], [1200, 600], [0, 84, 1200, 600]],
			'no size'        => [$source, [null, null], [1200, 768], [0, 0, 1200, 768]],
		];
	}

	#[DataProvider('forCropProvider')]
	public function testForCrop(
		array $source,
		array $options,
		array $size,
		array $region
	): void {
		$source = $this->source(...$source);
		$options  = new Options(width: $options[0], height: $options[1], crop: 'center');

		$plan = Plan::for($source, $options);

		$this->assertEquals(new Size(...$size), $plan->size());
		$this->assertEquals(new Size(...$size), $plan->resample());
		$this->assertEquals(new Rect(...$region), $plan->region());
	}

	public static function forCropCappedProvider(): array
	{
		return [
			'square, top left'     => [[400, 400], [800, 800], 'top left', [400, 400], [0, 0, 400, 400]],
			'square, bottom right' => [[400, 400], [800, 800], 'bottom right', [400, 400], [0, 0, 400, 400]],
			'square, center'       => [[400, 400], [800, 800], 'center', [400, 400], [0, 0, 400, 400]],
			'square, focus'        => [[400, 400], [800, 800], '20% 80%', [400, 400], [0, 0, 400, 400]],
			'same ratio'           => [[400, 300], [800, 600], 'center', [400, 300], [0, 0, 400, 300]],

			'wide, top left'     => [[1000, 500], [800, 800], 'top left', [500, 500], [0, 0, 500, 500]],
			'wide, bottom right' => [[1000, 500], [800, 800], 'bottom right', [500, 500], [500, 0, 500, 500]],
			'wide, center'       => [[1000, 500], [800, 800], 'center', [500, 500], [250, 0, 500, 500]],
			'wide, focus'        => [[1000, 500], [800, 800], '70% 30%', [500, 500], [450, 0, 500, 500]],
			'wider'              => [[1000, 500], [1200, 300], 'center', [1000, 250], [0, 125, 1000, 250]],
			'a few pixels'       => [[12, 8], [1200, 300], 'center', [12, 3], [0, 3, 12, 3]],

			'exactly the region' => [[1000, 500], [500, 500], 'center', [500, 500], [250, 0, 500, 500]],

			// a region of 10 × 4, cut from 10 × 4.29, which the thumb fills
			'rounded once' => [[10, 10], [700, 300], 'center', [10, 4], [0, 3, 10, 4]],

			'never narrower than a pixel' => [[6000, 10], [50, 1000], 'center', [1, 10], [3000, 0, 1, 10]],

			'never thinner than a pixel' => [[10, 10], [3000, 100], 'center', [10, 1], [0, 5, 10, 1]],
		];
	}

	#[DataProvider('forCropCappedProvider')]
	public function testForCropCapped(
		array $source,
		array $requested,
		string $crop,
		array $size,
		array $region
	): void {
		$source    = $this->source(...$source);
		$requested = new Size(...$requested);
		$options     = new Options(
			width:  $requested->width,
			height: $requested->height,
			crop:   $crop
		);

		$plan      = Plan::for($source, $options);
		$deviation = static::deviation($plan->size(), $requested);

		$this->assertEquals(new Size(...$size), $plan->size());
		$this->assertEquals(new Size(...$size), $plan->resample());
		$this->assertEquals(new Rect(...$region), $plan->region());
		$this->assertLessThan(1, $deviation);
	}

	public static function forCropRegionProvider(): array
	{
		return [
			'wide, top left'     => [[1200, 700], [400, 350], '0%, 0%', [0, 0, 800, 700]],
			'wide, top right'    => [[1200, 700], [400, 350], '100%, 0%', [400, 0, 800, 700]],
			'wide, bottom left'  => [[1200, 700], [400, 350], '0%, 100%', [0, 0, 800, 700]],
			'wide, bottom right' => [[1200, 700], [400, 350], '100%, 100%', [400, 0, 800, 700]],
			'wide, center'       => [[1200, 700], [400, 350], '50%, 50%', [200, 0, 800, 700]],
			'tall, top left'     => [[700, 1200], [400, 350], '0%, 0%', [0, 0, 700, 612]],
			'tall, top right'    => [[700, 1200], [400, 350], '100%, 0%', [0, 0, 700, 612]],
			'tall, bottom left'  => [[700, 1200], [400, 350], '0%, 100%', [0, 588, 700, 612]],
			'tall, bottom right' => [[700, 1200], [400, 350], '100%, 100%', [0, 588, 700, 612]],
			'tall, center'       => [[700, 1200], [400, 350], '50%, 50%', [0, 294, 700, 612]],

			'same ratio' => [[1200, 700], [600, 350], '30% 70%', [0, 0, 1200, 700]],

			'cut side from the sizes' => [[1200, 700], [70, 50], 'center', [110, 0, 980, 700]],
		];
	}

	#[DataProvider('forCropRegionProvider')]
	public function testForCropRegion(
		array $source,
		array $size,
		string $crop,
		array $region
	): void {
		$source = $this->source(...$source);
		$options  = new Options(width: $size[0], height: $size[1], crop: $crop);

		$plan = Plan::for($source, $options);

		$this->assertEquals(new Size(...$size), $plan->size());
		$this->assertEquals(new Rect(...$region), $plan->region());
	}

	public function testForDefaults(): void
	{
		$source   = $this->source(1200, 768);
		$plain    = new Options();
		$own      = new Options(quality: 30, format: Format::Png, interlace: false, icc: true);
		$defaults = new Defaults(quality: 20, format: Format::Webp, interlace: true, icc: false);

		$plan = Plan::for($source, $plain);

		$this->assertSame(Format::Jpeg, $plan->format);
		$this->assertSame(90, $plan->quality);
		$this->assertFalse($plan->interlace);
		$this->assertTrue($plan->icc);

		$plan = Plan::for($source, $plain, $defaults);

		$this->assertSame(Format::Webp, $plan->format);
		$this->assertSame(20, $plan->quality);
		$this->assertTrue($plan->interlace);
		$this->assertFalse($plan->icc);

		$plan = Plan::for($source, $own, $defaults);

		$this->assertSame(Format::Png, $plan->format);
		$this->assertSame(30, $plan->quality);
		$this->assertFalse($plan->interlace);
		$this->assertTrue($plan->icc);
	}

	public function testForEmpty(): void
	{
		$file    = $this->file('empty.jpg', "\xFF\xD8\xFF\xE0");
		$source  = new Source($file);
		$resize  = new Options(width: 300);
		$crop    = new Options(width: 300, height: 200, crop: 'center');
		$decoded = new Size(300, 200);

		$plan = Plan::for($source, $resize);

		$this->assertEquals(new Size(0, 0), $plan->size());
		$this->assertEquals(new Rect(0, 0, 0, 0), $plan->region());
		$this->assertEquals(new Rect(0, 0, 0, 0), $plan->region($decoded));
		$this->assertEquals(new Size(0, 0), $plan->scale());
		$this->assertEquals(new Size(0, 0), $plan->jpegSize());

		$plan = Plan::for($source, $crop);

		$this->assertEquals(new Size(300, 200), $plan->size());
		$this->assertEquals(new Size(300, 200), $plan->resample());
		$this->assertEquals(new Rect(0, 0, 0, 0), $plan->region());
		$this->assertEquals(new Rect(0, 0, 0, 0), $plan->region($decoded));
		$this->assertEquals(new Size(0, 0), $plan->scale());
		$this->assertEquals(new Size(0, 0), $plan->jpegSize());
	}

	public function testForFormat(): void
	{
		$source = $this->source(1200, 768);
		$plain  = new Options();
		$webp   = new Options(format: Format::Webp);

		$same  = Plan::for($source, $plain);
		$other = Plan::for($source, $webp);

		$this->assertSame($source, $same->source);
		$this->assertSame(Format::Jpeg, $same->format);
		$this->assertSame(Format::Webp, $other->format);
	}

	public function testForFrames(): void
	{
		$source    = $this->source(1200, 768);
		$options     = new Options(format: Format::Webp);
		$driver    = new FakeDriver();
		$animating = new FakeDriver(['animate' => Format::cases()]);

		$plain  = Plan::for($source, $options);
		$asked  = Plan::for($source, $options, driver: $driver);
		$single = Plan::for($source, $options, driver: $animating);

		$this->assertFalse($plain->frames);

		$this->assertFalse($asked->frames);
		$this->assertSame(['animate:Webp'], $driver->runs);

		$this->assertFalse($single->frames);
	}

	public function testForOptions(): void
	{
		$source = $this->source(1200, 768);
		$options  = new Options(blur: 10, grayscale: true, sharpen: 50);

		$plan = Plan::for($source, $options);

		$this->assertSame($options, $plan->options);
	}

	public static function forOrientationProvider(): array
	{
		$cases = [
			'Landscape_1' => [Orientation::None, [0, 0, 90, 120], [60, 40], [0, 0, 30, 40]],
			'Landscape_2' => [Orientation::FlipHorizontal, [90, 0, 90, 120], [60, 40], [30, 0, 30, 40]],
			'Landscape_3' => [Orientation::Rotate180, [90, 0, 90, 120], [60, 40], [30, 0, 30, 40]],
			'Landscape_4' => [Orientation::FlipVertical, [0, 0, 90, 120], [60, 40], [0, 0, 30, 40]],
			'Landscape_5' => [Orientation::Transpose, [0, 0, 120, 90], [40, 60], [0, 0, 40, 30]],
			'Landscape_6' => [Orientation::Rotate90, [0, 90, 120, 90], [40, 60], [0, 30, 40, 30]],
			'Landscape_7' => [Orientation::Transverse, [0, 90, 120, 90], [40, 60], [0, 30, 40, 30]],
			'Landscape_8' => [Orientation::Rotate270, [0, 0, 120, 90], [40, 60], [0, 0, 40, 30]],
			'Portrait_1'  => [Orientation::None, [0, 0, 120, 160], [30, 45], [0, 0, 30, 40]],
			'Portrait_2'  => [Orientation::FlipHorizontal, [0, 0, 120, 160], [30, 45], [0, 0, 30, 40]],
			'Portrait_3'  => [Orientation::Rotate180, [0, 20, 120, 160], [30, 45], [0, 5, 30, 40]],
			'Portrait_4'  => [Orientation::FlipVertical, [0, 20, 120, 160], [30, 45], [0, 5, 30, 40]],
			'Portrait_5'  => [Orientation::Transpose, [0, 0, 160, 120], [45, 30], [0, 0, 40, 30]],
			'Portrait_6'  => [Orientation::Rotate90, [0, 0, 160, 120], [45, 30], [0, 0, 40, 30]],
			'Portrait_7'  => [Orientation::Transverse, [20, 0, 160, 120], [45, 30], [5, 0, 40, 30]],
			'Portrait_8'  => [Orientation::Rotate270, [20, 0, 160, 120], [45, 30], [5, 0, 40, 30]],
		];

		foreach ($cases as $name => $case) {
			$cases[$name] = [$name . '.jpg', ...$case];
		}

		return $cases;
	}

	#[DataProvider('forOrientationProvider')]
	public function testForOrientation(
		string $file,
		Orientation $orientation,
		array $region,
		array $scale,
		array $scaled
	): void {
		$source    = new Source(self::FIXTURES . '/orientation/' . $file);
		$options     = new Options(width: 30, height: 40, crop: 'top left');
		$size      = new Size(30, 40);
		$resample  = $orientation->apply($size);
		$driver    = new FakeDriver();
		$orienting = new FakeDriver(['orients' => true]);
		$displayed = $source->size();
		$landscape = $displayed->isLandscape();
		$upright   = $landscape === true ? new Rect(0, 0, 90, 120) : new Rect(0, 0, 120, 160);
		$fit       = $landscape === true ? new Size(60, 40) : new Size(30, 45);

		$plans = [
			Plan::for($source, $options),
			Plan::for($source, $options, driver: $driver)
		];

		foreach ($plans as $plan) {
			$loaded = $plan->scale();

			$this->assertEquals($size, $plan->size());
			$this->assertEquals($resample, $plan->resample());
			$this->assertSame($orientation, $plan->orientation);
			$this->assertEquals(new Rect(...$region), $plan->region());
			$this->assertEquals(new Size(...$scale), $loaded);
			$this->assertEquals(new Rect(...$scaled), $plan->region($loaded));
		}

		$plan   = Plan::for($source, $options, driver: $orienting);
		$loaded = $plan->scale();

		$this->assertEquals($size, $plan->size());
		$this->assertEquals($size, $plan->resample());
		$this->assertSame(Orientation::None, $plan->orientation);
		$this->assertEquals($upright, $plan->region());
		$this->assertEquals($fit, $loaded);
		$this->assertEquals(new Rect(0, 0, 30, 40), $plan->region($loaded));
	}

	public static function forResizeProvider(): array
	{
		return [
			'width'                    => [[1200, 768], [500, null], [500, 320]],
			'width, larger'            => [[1200, 768], [2000, null], [1200, 768]],
			'height'                   => [[1200, 768], [null, 500], [781, 500]],
			'height, larger'           => [[1200, 768], [null, 2000], [1200, 768]],
			'landscape, height limits' => [[1200, 768], [1000, 500], [781, 500]],
			'portrait, width limits'   => [[768, 1200], [500, 1000], [500, 781]],
			'box, wider than tall'     => [[1200, 768], [500, 500], [500, 320]],
			'box, taller than wide'    => [[768, 1200], [500, 500], [320, 500]],
			'box, square'              => [[1200, 1200], [500, 500], [500, 500]],
			'box, larger'              => [[300, 200], [500, 500], [300, 200]],
			'no size'                  => [[1200, 768], [null, null], [1200, 768]],

			'both sides limit, rounded once' => [[1000, 333], [100, 20], [60, 20]],
		];
	}

	#[DataProvider('forResizeProvider')]
	public function testForResize(
		array $source,
		array $options,
		array $size
	): void {
		$full   = new Rect(0, 0, ...$source);
		$source = $this->source(...$source);
		$options  = new Options(width: $options[0], height: $options[1]);

		$plan = Plan::for($source, $options);

		$this->assertEquals(new Size(...$size), $plan->size());
		$this->assertEquals(new Size(...$size), $plan->resample());
		$this->assertEquals($full, $plan->region());
	}

	public static function forUnsupportedProvider(): array
	{
		return [
			'missing'           => [null, null],
			'empty'             => ['', null],
			'no image'          => ['text', null],
			'missing, as WebP'  => [null, Format::Webp],
			'empty, as WebP'    => ['', Format::Webp],
			'no image, as WebP' => ['text', Format::Webp],
		];
	}

	#[DataProvider('forUnsupportedProvider')]
	public function testForUnsupported(string|null $data, Format|null $format): void
	{
		$file   = $this->file('image.jpg', $data);
		$source = new Source($file);
		$options  = new Options(format: $format);

		$this->expectException(UnsupportedException::class);

		Plan::for($source, $options);
	}

	public static function jpegSizeProvider(): array
	{
		$none    = Orientation::None;
		$rotated = Orientation::Rotate90;

		return [
			'3/8'             => [[6000, 4000], $none, [1000, null, null], [1875, 1250]],
			'stored rotated'  => [[6000, 4000], $rotated, [1000, null, null], [1250, 1875]],
			'crop'            => [[6000, 4000], $none, [800, 600, 'center'], [1125, 750]],
			'2/8'             => [[6000, 4000], $none, [750, null, null], [1125, 750]],
			'just above 2/8'  => [[6000, 4000], $none, [1001, null, null], [1875, 1250]],
			'4/8'             => [[6000, 4000], $none, [1600, null, null], [2625, 1750]],
			'6/8'             => [[6000, 4000], $none, [2999, null, null], [4125, 2750]],
			'8/8'             => [[6000, 4000], $none, [4000, null, null], [5625, 3750]],
			'capped'          => [[6000, 4000], $none, [4001, null, null], [5625, 3750]],
			'a few pixels'    => [[26, 4], $none, [7, null, null], [12, 2]],

			'1/8'             => [[6000, 4000], $none, [400, null, null], [400, 267]],
			'the whole image' => [[6000, 4000], $none, [null, null, null], [6000, 4000]],
			'larger crop'     => [[180, 120], $none, [360, 360, 'center'], [180, 120]],

			'562 × 375'      => [[6000, 4000], $none, [562, null, null], [1125, 750]],
			'374 × 281'      => [[4032, 3024], $none, [374, null, null], [756, 567]],
			'150 × 100'      => [[1601, 1067], $none, [150, null, null], [301, 201]],
			'91 × 137'       => [[999, 1501], $none, [91, null, null], [188, 282]],
			'half an eighth' => [[4000, 3000], $none, [375, null, null], [750, 563]],
		];
	}

	#[DataProvider('jpegSizeProvider')]
	public function testJpegSize(
		array $source,
		Orientation $orientation,
		array $options,
		array $size
	): void {
		$source = $this->source(...$source, orientation: $orientation);
		$options  = new Options(width: $options[0], height: $options[1], crop: $options[2]);
		$plan   = Plan::for($source, $options);

		$this->assertEquals(new Size(...$size), $plan->jpegSize());
	}

	public static function keyProvider(): array
	{
		$square = ['width' => 300, 'height' => 300];

		return [
			'whole image'      => [[], '1200x800-q90'],
			'resize'           => [['width' => 300], '300x200-q90'],
			'larger'           => [['width' => 2000], '1200x800-q90'],
			'crop'             => [[...$square, 'crop' => 'center'], '300x300-crop200-0-800-800-q90'],
			'crop left'        => [[...$square, 'crop' => 'left'], '300x300-crop0-0-800-800-q90'],
			'same crop'        => [[...$square, 'crop' => '10% 50%'], '300x300-crop0-0-800-800-q90'],
			'crop, same ratio' => [['width' => 600, 'height' => 400, 'crop' => true], '600x400-q90'],
			'filters'          => [
				['width' => 300, 'blur' => 5, 'grayscale' => true, 'sharpen' => 25],
				'300x200-blur5-grayscale-sharpen25-q90'
			],
			'encoding' => [
				['width' => 300, 'quality' => 80, 'interlace' => true, 'icc' => false],
				'300x200-q80-interlace-noicc'
			],
		];
	}

	#[DataProvider('keyProvider')]
	public function testKey(array $options, string $key): void
	{
		$source  = $this->source(1200, 800);
		$options = Options::from($options);
		$plan    = Plan::for($source, $options);

		$this->assertSame($key, $plan->key());
	}

	public function testRegion(): void
	{
		$left    = new Options(width: 30, height: 40, crop: 'top left');
		$right   = new Options(width: 30, height: 40, crop: 'top right');
		$flipped = new Source(self::FIXTURES . '/orientation/Landscape_2.jpg');
		$rotated = new Source(self::FIXTURES . '/orientation/Landscape_6.jpg');
		$upright = new Source(self::FIXTURES . '/orientation/Landscape_1.jpg');
		$half    = new Size(90, 60);
		$eighth  = new Size(23, 15);

		$plan = Plan::for($flipped, $left);

		$this->assertEquals(new Rect(45, 0, 45, 60), $plan->region($half));

		$this->assertEquals(new Rect(11, 0, 12, 15), $plan->region($eighth));

		$plan     = Plan::for($rotated, $left);
		$portrait = new Size(60, 90);
		$full     = new Size(120, 180);

		$this->assertEquals(new Rect(0, 45, 60, 45), $plan->region($portrait));
		$this->assertEquals($plan->region(), $plan->region($full));

		$plan = Plan::for($upright, $right);

		$this->assertEquals(new Rect(11, 0, 12, 15), $plan->region($eighth));
	}

	public static function regionOrientationsProvider(): array
	{
		return [
			'portrait, center'  => [[3024, 4032], [100, 100, 'center']],
			'portrait, top'     => [[3024, 4032], [200, 300, 'top']],
			'portrait, right'   => [[3024, 4032], [500, 500, 'right']],
			'landscape, center' => [[4032, 3024], [100, 100, 'center']],
			'off centre'        => [[6000, 4000], [800, 600, '30% 70%']],
			'resize'            => [[1601, 1067], [300, null, null]],
		];
	}

	#[DataProvider('regionOrientationsProvider')]
	public function testRegionOrientations(array $size, array $options): void
	{
		$options   = new Options(width: $options[0], height: $options[1], crop: $options[2]);
		$source  = $this->source(...$size);
		$upright = Plan::for($source, $options);
		$scale   = $upright->scale();
		$hint    = $upright->jpegSize();
		$scaled  = $upright->region($scale);
		$hinted  = $upright->region($hint);

		foreach (Orientation::cases() as $orientation) {
			$source   = $this->source(...$size, orientation: $orientation);
			$plan     = Plan::for($source, $options);
			$expected = $orientation->map($scaled, $scale);
			$loaded   = $plan->scale();

			$this->assertEquals($expected, $plan->region($loaded), $orientation->name);

			$expected = $orientation->map($hinted, $hint);
			$loaded   = $plan->jpegSize();

			$this->assertEquals($expected, $plan->region($loaded), $orientation->name);
		}
	}

	public static function scaleProvider(): array
	{
		return [
			'tall crop' => [[4000, 6000], [300, 536, 'center'], [357, 536], [29, 0, 300, 536]],
			'wide crop' => [[4000, 6000], [800, 200, 'center'], [800, 1200], [0, 500, 800, 200]],

			'larger crop' => [[180, 120], [360, 360, 'center'], [180, 120], [30, 0, 120, 120]],

			'resize' => [[1200, 768], [500, null, null], [500, 320], [0, 0, 500, 320]],
		];
	}

	#[DataProvider('scaleProvider')]
	public function testScale(
		array $source,
		array $options,
		array $scale,
		array $region
	): void {
		$source = $this->source(...$source);
		$options  = new Options(width: $options[0], height: $options[1], crop: $options[2]);
		$plan   = Plan::for($source, $options);

		$loaded = $plan->scale();

		$this->assertEquals(new Size(...$scale), $loaded);
		$this->assertEquals(new Rect(...$region), $plan->region($loaded));
	}

	public function testScaleRegionInvariants(): void
	{
		$engine  = new Mt19937(42);
		$random  = new Randomizer($engine);
		$crops   = [null, 'center', 'top left', 'bottom right', '30% 70%', '100% 0%'];
		$drivers = [new FakeDriver(), new FakeDriver(['orients' => true])];

		$side = fn (int $max): int => $random->getInt(0, 4) === 0
			? $random->getInt(1, 20)
			: $random->getInt(1, $max);

		$option = fn (): int|null => $random->getInt(0, 3) === 0 ? null : $side(3000);

		for ($run = 0; $run < 400; $run++) {
			$width       = $side(6000);
			$height      = $side(6000);
			$value       = $random->getInt(1, 8);
			$orientation = Orientation::from($value);
			$source      = $this->source($width, $height, $orientation);
			$displayed   = $source->size();
			$options       = new Options(
				width:  $option(),
				height: $option(),
				crop:   $crops[$random->getInt(0, count($crops) - 1)]
			);

			foreach ($drivers as $driver) {
				$plan     = Plan::for($source, $options, driver: $driver);
				$canvas   = $plan->orientation->apply($displayed);
				$resample = $plan->resample();
				$region   = $plan->region();
				$scale    = $plan->scale();
				$oriented = $plan->orientation->apply($resample);
				$case     = sprintf(
					'%d×%d, %s, %s × %s, %s',
					$canvas->width,
					$canvas->height,
					$plan->orientation->name,
					$options->width ?? '-',
					$options->height ?? '-',
					$options->crop ?? 'no crop'
				);

				$this->assertEquals($plan->size(), $oriented, $case);
				$this->assertInside($region, $canvas, $case);
				$this->assertGreaterThan(0, $region->width, $case);
				$this->assertGreaterThan(0, $region->height, $case);

				if ($options->crop === null) {
					$whole = new Rect(0, 0, $canvas->width, $canvas->height);

					$this->assertEquals($whole, $region, $case);
				} else {
					$requested = new Size(
						width:  $options->width ?? $options->height ?? $displayed->width,
						height: $options->height ?? $options->width ?? $displayed->height
					);
					$requested = $plan->orientation->apply($requested);
					$cut       = static::deviation($region, $requested);
					$resampled = static::deviation($resample, $requested);

					$this->assertLessThan(1, $cut, $case);
					$this->assertLessThan(1, $resampled, $case);
				}

				$this->assertLessThanOrEqual($canvas->width, $scale->width, $case);
				$this->assertLessThanOrEqual($canvas->height, $scale->height, $case);

				$scaled = $plan->region($scale);

				$this->assertInside($scaled, $scale, $case);

				$this->assertLessThanOrEqual($region->width, $resample->width, $case);
				$this->assertLessThanOrEqual($region->height, $resample->height, $case);
				$this->assertSame($resample->width, $scaled->width, $case);
				$this->assertSame($resample->height, $scaled->height, $case);

				if ($plan->orientation === Orientation::None && $options->crop === 'bottom right') {
					$this->assertSame($canvas->width, $region->x + $region->width, $case);
					$this->assertSame($canvas->height, $region->y + $region->height, $case);
					$this->assertSame($scale->width, $scaled->x + $scaled->width, $case);
					$this->assertSame($scale->height, $scaled->y + $scaled->height, $case);
				}

				$hint    = $plan->jpegSize();
				$nearest = (int)round(8 * max(
					$hint->width / $canvas->width,
					$hint->height / $canvas->height
				));
				$shrink = (int)min(
					$canvas->width / $hint->width,
					$canvas->height / $hint->height
				);

				$this->assertLessThanOrEqual($canvas->width, $hint->width, $case);
				$this->assertLessThanOrEqual($canvas->height, $hint->height, $case);
				$this->assertGreaterThanOrEqual($scale->width, $hint->width, $case);
				$this->assertGreaterThanOrEqual($scale->height, $hint->height, $case);

				foreach ([$nearest, (int)ceil(8 / $shrink)] as $eighths) {
					$decoded = new Size(
						width:  (int)ceil($canvas->width * $eighths / 8),
						height: (int)ceil($canvas->height * $eighths / 8)
					);

					$this->assertGreaterThanOrEqual($scale->width, $decoded->width, $case);
					$this->assertGreaterThanOrEqual($scale->height, $decoded->height, $case);
				}

				for ($eighths = 1; $eighths <= 8; $eighths++) {
					$decoded = new Size(
						width:  (int)ceil($canvas->width * $eighths / 8),
						height: (int)ceil($canvas->height * $eighths / 8)
					);

					$this->assertInside($plan->region($decoded), $decoded, $case);
				}

				$decoded = new Size(
					width:  $random->getInt(1, 2 * $canvas->width),
					height: $random->getInt(1, 2 * $canvas->height)
				);

				$this->assertInside($plan->region($decoded), $decoded, $case);
			}
		}
	}

	public function testSize(): void
	{
		$source  = $this->source(1200, 800);
		$options = new Options(width: 300);
		$plan    = Plan::for($source, $options);

		$this->assertEquals(new Size(300, 200), $plan->size());
		$this->assertSame(300, $plan->width());
		$this->assertSame(200, $plan->height());
		$this->assertSame(1.5, $plan->ratio());
		$this->assertTrue($plan->isLandscape());
		$this->assertFalse($plan->isPortrait());
		$this->assertFalse($plan->isSquare());
	}

	public function testToArray(): void
	{
		$source   = $this->source(1200, 800);
		$options  = new Options(width: 300, crop: 'top left');
		$defaults = new Defaults(quality: 80, icc: false);
		$plan     = Plan::for($source, $options, $defaults);

		$array  = $plan->toArray();
		$filled = Options::from($array);
		$again  = Plan::for($source, $filled);

		$this->assertSame([
			'quality'   => 80,
			'format'    => 'jpg',
			'interlace' => false,
			'icc'       => false,
			'width'     => 300,
			'crop'      => 'top left',
		], $array);

		$this->assertSame($array, $again->toArray());
		$this->assertEquals($plan->crop, $again->crop);
		$this->assertEquals($plan->size(), $again->size());
	}
}
