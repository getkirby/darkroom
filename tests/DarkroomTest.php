<?php

namespace Darkroom\Tests;

use Darkroom\Darkroom;
use Darkroom\Defaults;
use Darkroom\Driver;
use Darkroom\Drivers\Gd;
use Darkroom\Drivers\Imagick;
use Darkroom\Drivers\Vips;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Exception\UnsupportedException;
use Darkroom\Format;
use Darkroom\Meta;
use Darkroom\Options;
use Darkroom\Orientation;
use Darkroom\Settings;
use Darkroom\Size;
use Darkroom\Source;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use stdClass;

#[CoversClass(Darkroom::class)]
class DarkroomTest extends TestCase
{
	use Required;

	protected const string EXIF = __DIR__ . '/fixtures/exif.jpg';
	protected const string GIF  = __DIR__ . '/fixtures/gif/animated.gif';
	protected const string HEIC = __DIR__ . '/fixtures/heif/plain.heic';
	protected const string JPEG = __DIR__ . '/fixtures/orientation/Landscape_1.jpg';
	protected const string PNG  = __DIR__ . '/fixtures/png/plain.png';

	protected function files(string $dir = ''): array
	{
		$names = scandir($this->tmp() . $dir);

		return array_values(array_diff($names, ['.', '..']));
	}

	public function testConstruct(): void
	{
		$darkroom = new Darkroom();

		$this->assertSame(['imagick', 'gd', 'vips'], $darkroom->drivers);
		$this->assertEquals(new Defaults(), $darkroom->defaults);
		$this->assertEquals(new Settings(), $darkroom->settings);
	}

	public function testConstructDefaults(): void
	{
		$defaults = new Defaults(quality: 80);
		$darkroom = new Darkroom([], $defaults);

		$this->assertSame($defaults, $darkroom->defaults);
	}

	public function testConstructDrivers(): void
	{
		$fake = new FakeDriver();

		$darkroom = new Darkroom(['gd', 'imagick', 'vips', FakeDriver::class, $fake]);
		$drivers  = (fn () => $this->instances)->call($darkroom);

		$this->assertInstanceOf(Gd::class, $drivers[0]);
		$this->assertInstanceOf(Imagick::class, $drivers[1]);
		$this->assertInstanceOf(Vips::class, $drivers[2]);
		$this->assertInstanceOf(FakeDriver::class, $drivers[3]);
		$this->assertNotSame($fake, $drivers[3]);
		$this->assertSame($fake, $drivers[4]);
	}

	public static function constructInvalidDefaultsProvider(): array
	{
		return [
			'quality'   => [['quality' => 'high'], 'Invalid quality: \'high\''],
			'negative'  => [['quality' => -1], 'Invalid quality: -1'],
			'interlace' => [['interlace' => 'flase'], 'Invalid interlace: \'flase\''],
			'meta'      => [['meta' => 'color'], 'Invalid meta: \'color\''],
			'meta name' => [['meta' => ['icc']], 'Invalid meta: \'icc\''],
		];
	}

	#[DataProvider('constructInvalidDefaultsProvider')]
	public function testConstructInvalidDefaults(array $defaults, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new Darkroom([], $defaults);
	}

	public static function constructInvalidDriverProvider(): array
	{
		return [
			'name'     => ['magick'],
			'class'    => [stdClass::class],
			'abstract' => [Driver::class],
		];
	}

	#[DataProvider('constructInvalidDriverProvider')]
	public function testConstructInvalidDriver(string $driver): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown driver: ' . $driver);

		new Darkroom([$driver]);
	}

	public function testConstructInvalidLimit(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid limit: \'5\'');

		new Darkroom([], settings: ['limit' => '5']);
	}

	public function testConstructLimit(): void
	{
		$none     = new Darkroom([FakeDriver::class], settings: ['limit' => null]);
		$set      = new Darkroom([FakeDriver::class], settings: ['limit' => 5]);
		$driver   = $none->driver(Format::Jpeg, Format::Jpeg);
		$settings = $driver->settings();

		$this->assertNull($none->settings->limit);
		$this->assertSame(5, $set->settings->limit);
		$this->assertNull($settings['limit']);
	}

	public function testConstructSettings(): void
	{
		$defaults = [
			'quality'   => '80',
			'interlace' => 'on',
			'meta'      => ['camera'],
		];

		$settings = [
			'limit'   => 1000,
			'threads' => 4,
			'unknown' => [],
		];

		$darkroom = new Darkroom([FakeDriver::class], $defaults, $settings);
		$driver   = $darkroom->driver(Format::Jpeg, Format::Jpeg);
		$own      = $driver->settings();

		$this->assertSame(1000, $darkroom->settings->limit);
		$this->assertSame(['threads' => 4, 'unknown' => []], $darkroom->settings->other);
		$this->assertSame(80, $darkroom->defaults->quality);
		$this->assertSame(4, $own['threads']);
		$this->assertSame(1000, $own['limit']);
		$this->assertArrayNotHasKey('quality', $own);
	}

	public function testDriver(): void
	{
		$first    = new FakeDriver(['read' => [Format::Jpeg], 'write' => [Format::Jpeg]]);
		$second   = new FakeDriver(['write' => [Format::Jpeg, Format::Webp]]);
		$darkroom = new Darkroom([$first, $second]);

		$this->assertSame($first, $darkroom->driver(Format::Jpeg, Format::Jpeg));
		$this->assertSame($second, $darkroom->driver(Format::Jpeg, Format::Webp));
		$this->assertSame($second, $darkroom->driver(Format::Png, Format::Jpeg));
		$this->assertNull($darkroom->driver(Format::Png, Format::Png));
	}

	public function testDriverOnce(): void
	{
		$darkroom = new Darkroom([FakeDriver::class]);
		$driver   = $darkroom->driver(Format::Jpeg, Format::Png);

		$this->assertSame($driver, $darkroom->driver(Format::Jpeg, Format::Png));
		$this->assertSame(['read:Jpeg', 'write:Png'], $driver->runs);
	}

	public function testDriverSource(): void
	{
		$first    = new FakeDriver(['refuse' => [self::GIF]]);
		$second   = new FakeDriver();
		$darkroom = new Darkroom([$first, $second]);
		$gif      = new Source(self::GIF);
		$jpeg     = new Source(self::JPEG);

		$this->assertSame($first, $darkroom->driver(Format::Gif, Format::Png));
		$this->assertSame($second, $darkroom->driver($gif, Format::Png));
		$this->assertSame($first, $darkroom->driver($jpeg, Format::Png));
	}

	public function testDriverStrings(): void
	{
		$driver   = new FakeDriver(['read' => [Format::Jpeg], 'write' => [Format::Webp]]);
		$darkroom = new Darkroom([$driver]);

		$this->assertSame($driver, $darkroom->driver('jpg', 'image/webp'));
		$this->assertNull($darkroom->driver('webp', 'jpeg'));

		$this->expectException(InvalidArgumentException::class);

		$darkroom->driver('jpg', 'svg');
	}

	public function testDriverUnavailable(): void
	{
		$unavailable = new FakeDriver(['available' => false]);
		$available   = new FakeDriver();
		$darkroom    = new Darkroom([$unavailable, $available]);

		$this->assertSame($available, $darkroom->driver(Format::Jpeg, Format::Jpeg));
		$this->assertSame([], $unavailable->runs);
	}

	public function testDriverVips(): void
	{
		$vips     = new Vips();
		$next     = new FakeDriver();
		$darkroom = new Darkroom([$vips, $next]);
		$still    = new Source(__DIR__ . '/fixtures/heif/plain.avif');
		$hdr      = new Source(__DIR__ . '/fixtures/heif/hdr.avif');

		if ($vips->canRead(Format::Avif) === false) {
			$this->skip('libvips reads no AVIF', $vips, Format::Avif);
		}

		// none for HDR
		$this->assertSame($vips, $darkroom->driver($still, Format::Png));
		$this->assertNull($darkroom->driver($hdr, Format::Png));
	}

	public static function formatProvider(): array
	{
		$all  = Format::cases();
		$jpeg = [Format::Jpeg];
		$webp = [Format::Webp];

		return [
			'source'                 => [self::JPEG, null, ['jpg', 'webp'], $all, Format::Jpeg],
			'source not allowed'     => [self::GIF, null, ['webp', 'jpg'], $all, Format::Webp],
			'source not writable'    => [self::PNG, null, ['png', 'webp'], $webp, Format::Webp],
			'in the given order'     => [self::GIF, null, ['jpg', 'webp'], $all, Format::Jpeg],
			'first not writable'     => [self::GIF, null, ['webp', 'jpg'], $jpeg, Format::Jpeg],
			'requested'              => [self::JPEG, 'avif', ['avif', 'jpg'], $all, Format::Avif],
			'requested as MIME'      => [self::JPEG, 'image/png', ['png'], $all, Format::Png],
			'requested as a format'  => [self::JPEG, Format::Png, [Format::Png], $all, Format::Png],
			'requested, not allowed' => [self::JPEG, 'png', ['jpg', 'webp'], $all, Format::Png],
			'requested not writable' => [self::JPEG, 'webp', ['jpg', 'webp'], $jpeg, Format::Jpeg],
			'allowed as MIME'        => [self::JPEG, null, ['image/jpeg'], $all, Format::Jpeg],
		];
	}

	#[DataProvider('formatProvider')]
	public function testFormat(
		string $file,
		Format|string|null $requested,
		array $allowed,
		array $write,
		Format $expected
	): void {
		$driver   = new FakeDriver(['write' => $write]);
		$darkroom = new Darkroom([$driver]);
		$source   = new Source($file);

		$this->assertSame($expected, $darkroom->format($source, $requested, $allowed));
	}

	public static function formatUnreadableProvider(): array
	{
		return [
			'format' => [['read' => [Format::Png]]],
			'source' => [['refuse' => [self::JPEG]]],
		];
	}

	#[DataProvider('formatUnreadableProvider')]
	public function testFormatUnreadable(array $settings): void
	{
		$driver   = new FakeDriver($settings);
		$darkroom = new Darkroom([$driver]);
		$source   = new Source(self::JPEG);

		$this->expectException(UnsupportedException::class);

		$darkroom->format($source, null, ['jpg', 'webp']);
	}

	public static function formatUnsupportedProvider(): array
	{
		return [
			'nothing writable' => [null, ['jpg', 'webp'], [Format::Png]],
			'nothing allowed'  => [null, [], Format::cases()],
			'requested too'    => ['avif', ['jpg', 'webp'], [Format::Png]],
		];
	}

	#[DataProvider('formatUnsupportedProvider')]
	public function testFormatUnsupported(
		string|null $requested,
		array $allowed,
		array $write
	): void {
		$driver   = new FakeDriver(['write' => $write]);
		$darkroom = new Darkroom([$driver]);
		$source   = new Source(self::JPEG);

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('No driver converts to an allowed format: ' . self::JPEG);

		$darkroom->format($source, $requested, $allowed);
	}

	public static function formatViewableProvider(): array
	{
		$all  = Format::cases();
		$jpeg = [Format::Jpeg];

		return [
			'source'            => [self::JPEG, $all, Format::Jpeg],
			'hidden source'     => [self::HEIC, $all, Format::Webp],
			'webp not writable' => [self::HEIC, $jpeg, Format::Jpeg],
		];
	}

	#[DataProvider('formatViewableProvider')]
	public function testFormatViewable(
		string $file,
		array $write,
		Format $expected
	): void {
		$driver   = new FakeDriver(['write' => $write]);
		$darkroom = new Darkroom([$driver]);
		$source   = new Source($file);

		$this->assertSame($expected, $darkroom->format($source));
	}

	public function testInfo(): void
	{
		$available   = new FakeDriver([
			'read'  => [Format::Jpeg, Format::Png],
			'write' => [Format::Webp]
		]);
		$unavailable = new FakeDriver(['available' => false]);
		$darkroom    = new Darkroom(['main' => $available, 'other' => $unavailable]);

		$info = $darkroom->info();

		$this->assertSame([
			'main' => [
				'class'     => FakeDriver::class,
				'available' => true,
				'library'   => null,
				'version'   => null,
				'formats'   => [
					'read'  => ['jpg', 'png'],
					'write' => ['webp'],
				],
			],
			'other' => [
				'class'     => FakeDriver::class,
				'available' => false,
				'library'   => null,
				'version'   => null,
				'formats'   => [
					'read'  => [],
					'write' => [],
				],
			],
		], $info);

		$this->assertSame([], $unavailable->runs);
	}

	public function testOpen(): void
	{
		$darkroom = new Darkroom([]);

		$source = $darkroom->open(self::JPEG);

		$this->assertSame(self::JPEG, $source->file);
	}

	public function testPlan(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$source   = new Source(self::JPEG);
		$options    = new Options(width: 90);

		$plans = [
			$darkroom->plan(self::JPEG, ['width' => 90]),
			$darkroom->plan($source, $options),
		];

		foreach ($plans as $plan) {
			$this->assertEquals(new Size(90, 60), $plan->size());
			$this->assertSame(Format::Jpeg, $plan->format);
			$this->assertSame(85, $plan->quality);
		}

		$this->assertSame([], $driver->plans);
	}

	public function testPlanDefaults(): void
	{
		$driver   = new FakeDriver();
		$defaults = ['quality' => '80', 'interlace' => 'on', 'meta' => ['time']];
		$darkroom = new Darkroom([$driver], $defaults);

		$plan = $darkroom->plan(self::JPEG, []);

		$this->assertSame(80, $plan->quality);
		$this->assertTrue($plan->interlace);
		$this->assertSame([Meta::Time], $plan->meta);
		$this->assertFalse($plan->icc);
	}

	public function testPlanEmpty(): void
	{
		$darkroom = new Darkroom([]);
		$src      = $this->file('empty.jpg', "\xFF\xD8\xFF\xE0");

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('Image without a readable size: ' . $src);

		$darkroom->plan($src);
	}

	public static function planFormatProvider(): array
	{
		$all      = Format::cases();
		$viewable = [Format::Webp, Format::Jpeg];

		return [
			'source'                 => [self::JPEG, [], [], $all, Format::Jpeg],
			'requested'              => [self::JPEG, ['format' => 'avif'], [], $all, Format::Avif],
			'default'                => [self::JPEG, [], ['format' => 'webp'], $all, Format::Webp],
			'requested over default' => [self::JPEG, ['format' => 'png'], ['format' => 'webp'], $all, Format::Png],
			'requested not writable' => [self::JPEG, ['format' => 'avif'], [], $viewable, Format::Jpeg],
			'hidden source'          => [self::HEIC, [], [], $viewable, Format::Webp],
			'hidden, requested'      => [self::JPEG, ['format' => 'heic'], [], $all, Format::Heic],
		];
	}

	#[DataProvider('planFormatProvider')]
	public function testPlanFormat(
		string $file,
		array $options,
		array $defaults,
		array $write,
		Format $format
	): void {
		$driver   = new FakeDriver(['write' => $write]);
		$darkroom = new Darkroom([$driver], $defaults);

		$plan = $darkroom->plan($file, $options);

		$this->assertSame($format, $plan->format);
		$this->assertSame($format, $plan->options->format);
	}

	public function testPlanFrames(): void
	{
		$driver   = new FakeDriver(['animate' => Format::cases()]);
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('thumb.gif');

		$planned   = $darkroom->plan(self::GIF);
		$processed = $darkroom->process($planned, $dst);

		$this->assertFalse($planned->frames);
		$this->assertTrue($processed->frames);
	}

	public function testPlanLimit(): void
	{
		$darkroom = new Darkroom([], settings: ['limit' => 180 * 120 - 1]);

		$this->expectException(LimitException::class);
		$this->expectExceptionMessage('Image above the limit of 21599 pixels: ' . self::JPEG);

		$darkroom->plan(self::JPEG);
	}

	public function testProcess(): void
	{
		$driver   = new FakeDriver(['animate' => Format::cases()]);
		$darkroom = new Darkroom([$driver], ['quality' => 80]);
		$dst      = $this->file('photo-90x.webp');
		$temp     = '!^' . preg_quote($this->tmp()) . '/\.photo-90x\.webp\.tmp-[0-9a-f]{12}$!';

		$plan = $darkroom->process(self::GIF, $dst, ['width' => 90, 'interlace' => true]);

		$this->assertSame([$plan], $driver->plans);
		$this->assertSame(Format::Webp, $plan->format);
		$this->assertSame(80, $plan->quality);
		$this->assertTrue($plan->interlace);
		$this->assertTrue($plan->frames);

		$this->assertSame('Webp', file_get_contents($dst));
		$this->assertSame(['photo-90x.webp'], $this->files());
		$this->assertMatchesRegularExpression($temp, $driver->files[0]);
	}

	public function testProcessDirectories(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('a/b/photo.jpg');

		$darkroom->process(self::JPEG, $dst, []);

		$this->assertSame(['photo.jpg'], $this->files('/a/b'));
	}

	public function testProcessEmpty(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('thumb.jpg');

		$src = $this->file('empty.jpg', "\xFF\xD8\xFF\xE0");

		try {
			$darkroom->process($src, $dst, ['width' => 100, 'crop' => true]);
			$this->fail('No exception');
		} catch (UnsupportedException $e) {
			$this->assertSame('Image without a readable size: ' . $src, $e->getMessage());
		}

		$this->assertSame([], $driver->runs);
		$this->assertSame(['empty.jpg'], $this->files());
	}

	public function testProcessException(): void
	{
		$error    = new ProcessException(message: 'Failed');
		$driver   = new FakeDriver(['throw' => $error]);
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('photo.jpg', 'old');

		try {
			$darkroom->process(self::JPEG, $dst, []);
			$this->fail('No exception');
		} catch (ProcessException $e) {
			$this->assertSame($error, $e);
		}

		$this->assertCount(1, $driver->files);
		$this->assertSame(['photo.jpg'], $this->files());
		$this->assertSame('old', file_get_contents($dst));
	}

	public function testProcessFatal(): void
	{
		$dst  = $this->file('photo.jpg');
		$code = <<<'PHP'
			require $argv[1];
			$driver   = new Darkroom\Tests\FakeDriver(['fatal' => true]);
			$darkroom = new Darkroom\Darkroom([$driver]);
			$darkroom->process($argv[2], $argv[3], []);
			PHP;

		$command = [
			PHP_BINARY,
			'-d',
			'memory_limit=64M',
			'-r',
			$code,
			'--',
			dirname(__DIR__) . '/vendor/autoload.php',
			self::JPEG,
			$dst
		];

		$streams = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $streams, $pipes);
		$output  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

		$this->assertSame(255, proc_close($process), $output);
		$this->assertSame([], $this->files());
	}

	public static function processFormatProvider(): array
	{
		return [
			'from the extension' => ['thumb.webp', [], Format::Webp],
			'upper case'         => ['thumb.PNG', [], Format::Png],
			'same format'        => ['thumb.jpg', ['format' => 'jpeg'], Format::Jpeg],
		];
	}

	#[DataProvider('processFormatProvider')]
	public function testProcessFormat(
		string $dst,
		array $options,
		Format $format
	): void {
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file($dst);

		$plan = $darkroom->process(self::JPEG, $dst, $options);

		$this->assertSame($format, $plan->format);
	}

	public static function processInvalidFormatProvider(): array
	{
		return [
			'unknown'      => ['thumb.svg', [], 'Unknown image format: svg'],
			'no extension' => ['thumb', [], 'Unknown image format: '],
			'other format' => [
				'thumb.png',
				['format' => 'webp'],
				"Format webp doesn't match: "
			],
		];
	}

	#[DataProvider('processInvalidFormatProvider')]
	public function testProcessInvalidFormat(
		string $dst,
		array $options,
		string $message
	): void {
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file($dst);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$darkroom->process(self::JPEG, $dst, $options);
	}

	public function testProcessLimit(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver], settings: ['limit' => 180 * 120]);
		$dst      = $this->file('photo.jpg');

		$plan = $darkroom->process(self::JPEG, $dst, []);

		$this->assertEquals(new Size(180, 120), $plan->size());

		// a crop larger than the image is capped at it
		$plan = $darkroom->process(self::JPEG, $dst, ['width' => 200, 'crop' => true]);

		$this->assertEquals(new Size(120, 120), $plan->size());
	}

	public function testProcessLimitSource(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver], settings: ['limit' => 180 * 120 - 1]);
		$dst      = $this->file('photo.jpg');

		$this->expectException(LimitException::class);
		$this->expectExceptionMessage('Image above the limit of 21599 pixels: ' . self::JPEG);

		try {
			$darkroom->process(self::JPEG, $dst, ['width' => 10]);
		} finally {
			$this->assertSame([], $driver->runs);
		}
	}

	#[RequiresFunction('exif_read_data')]
	public function testProcessMeta(): void
	{
		$image    = file_get_contents(__DIR__ . '/fixtures/progressive.jpg');
		$driver   = new FakeDriver(['image' => $image]);
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('thumb.jpg');

		$plan = $darkroom->process(self::EXIF, $dst, ['width' => 20, 'meta' => ['camera', 'color']]);

		$source = $darkroom->open($dst);
		$tags   = $source->exif();
		$key    = $plan->key();

		// without the orientation, which the driver applies
		$this->assertStringEndsWith('-meta-camera-color', $key->toString());
		$this->assertArrayHasKey('Make', $tags['IFD0']);
		$this->assertArrayNotHasKey('Orientation', $tags['IFD0']);
		$this->assertArrayNotHasKey('GPS', $tags);
		$this->assertSame(['thumb.jpg'], $this->files());
	}

	public function testProcessNoLimit(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver], settings: ['limit' => null]);
		$dst      = $this->file('photo.png');

		// twice the default limit
		$png = static::bare(20000, 20000);
		$src = $this->file('large.png', $png);

		$plan = $darkroom->process($src, $dst, ['width' => 100]);

		$this->assertEquals(new Size(100, 100), $plan->size());
	}

	public function testProcessOptions(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver], ['interlace' => true]);
		$dst      = $this->file('photo.png');
		$options    = new Options(
			width:     60,
			crop:      'top left',
			grayscale: true,
			format:    Format::Png,
			interlace: false
		);

		$plan = $darkroom->process(self::JPEG, $dst, $options);

		$this->assertEquals(new Size(60, 60), $plan->size());
		$this->assertTrue($plan->options->grayscale);
		$this->assertFalse($plan->interlace);
		$this->assertSame(Format::Png, $plan->format);
	}

	public function testProcessOverwrite(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$dst      = $this->file('photo.jpg', 'old');

		$darkroom->process(self::JPEG, $dst, []);

		$this->assertSame('Jpeg', file_get_contents($dst));
	}

	public function testProcessPlan(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$options  = ['width' => 90, 'crop' => 'top left', 'grayscale' => true];
		$dst      = $this->file('photo.jpg');
		$plan     = $darkroom->plan(self::JPEG, $options);
		$expected = $darkroom->process(self::JPEG, $dst, $options);

		$processed = $darkroom->process($plan, $dst);

		$this->assertEquals($expected, $processed);
		$this->assertSame($plan->source, $processed->source);
	}

	public function testProcessPlanFormat(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$plan     = $darkroom->plan(self::JPEG, ['format' => 'webp']);
		$dst      = $this->file('photo.png');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Format webp doesn't match: " . $dst);

		$darkroom->process($plan, $dst);
	}

	public static function processPlanOptionsProvider(): array
	{
		return [
			'array' => [['width' => 60]],
			'thumb' => [new Options(width: 60)],
		];
	}

	#[DataProvider('processPlanOptionsProvider')]
	public function testProcessPlanOptions(Options|array $options): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$plan     = $darkroom->plan(self::JPEG, ['width' => 90]);
		$dst      = $this->file('photo.jpg');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Either a plan or options, not both');

		try {
			$darkroom->process($plan, $dst, $options);
		} finally {
			$this->assertSame([], $driver->plans);
		}
	}

	public function testProcessRefused(): void
	{
		$first    = new FakeDriver(['refuse' => [self::GIF]]);
		$second   = new FakeDriver();
		$darkroom = new Darkroom([$first, $second]);
		$dst      = $this->file('thumb.png');

		$darkroom->process(self::GIF, $dst, []);

		$this->assertSame([], $first->plans);
		$this->assertCount(1, $second->plans);
	}

	public function testProcessSource(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$src      = $this->file('photo.jpg', file_get_contents(self::JPEG));
		$dst      = $this->file('thumb.jpg');
		$source   = $darkroom->open($src);

		$source->size();
		unlink($src);

		$plan = $darkroom->process($source, $dst, ['width' => 90]);

		$this->assertSame($source, $plan->source);
		$this->assertEquals(new Size(90, 60), $plan->size());
	}

	public function testProcessUnrecognised(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$src      = $this->file('text.jpg', 'text');
		$dst      = $this->file('thumb.jpg');

		$this->expectException(UnsupportedException::class);

		try {
			$darkroom->process($src, $dst, []);
		} finally {
			$this->assertSame([], $driver->runs);
		}
	}

	public function testProcessUnsupported(): void
	{
		$first    = new FakeDriver(['write' => [Format::Jpeg]]);
		$second   = new FakeDriver(['write' => [Format::Jpeg, Format::Png]]);
		$darkroom = new Darkroom([$first, $second]);
		$png      = $this->file('photo.png');
		$webp     = $this->file('photo.webp');

		$darkroom->process(self::JPEG, $png, []);

		$this->assertSame([], $first->plans);
		$this->assertCount(1, $second->plans);

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('No driver converts jpg to webp');

		$darkroom->process(self::JPEG, $webp, []);
	}

	public function testStrip(): void
	{
		$darkroom = new Darkroom([]);
		$original = $darkroom->open(self::EXIF);
		$dst      = $this->file('a/photo.jpg');

		$darkroom->strip(self::EXIF, $dst);

		$source = $darkroom->open($dst);

		$this->assertSame(['photo.jpg'], $this->files('/a'));
		$this->assertSame(Orientation::Rotate270, $source->orientation());
		$this->assertEquals($original->size(), $source->size());
		$this->assertLessThan(filesize(self::EXIF), filesize($dst));
	}

	#[RequiresFunction('exif_read_data')]
	public function testStripExif(): void
	{
		$darkroom = new Darkroom([]);
		$dst      = $this->file('photo.jpg');

		$darkroom->strip(self::EXIF, $dst);

		$source = $darkroom->open($dst);

		$this->assertSame(['IFD0' => ['Orientation' => 8]], $source->exif());
	}

	public static function stripFormatProvider(): array
	{
		return [
			'avif' => [Format::Avif, 'heif/exif.avif'],
			'gif'  => [Format::Gif, 'gif/animated.gif'],
			'heic' => [Format::Heic, 'heif/exif.heic'],
			'jpeg' => [Format::Jpeg, 'exif.jpg'],
			'jxl'  => [Format::Jxl, 'jxl/exif-6.jxl'],
			'png'  => [Format::Png, 'png/exif-6.png'],
			'webp' => [Format::Webp, 'webp/exif-6.webp'],
		];
	}

	#[DataProvider('stripFormatProvider')]
	public function testStripFormat(Format $format, string $file): void
	{
		$darkroom = new Darkroom([]);
		$src      = __DIR__ . '/fixtures/' . $file;
		$original = $darkroom->open($src);
		$dst      = $this->file('photo.' . $format->extension());

		$darkroom->strip($src, $dst);

		$source = $darkroom->open($dst);

		$this->assertSame($format, $source->format());
		$this->assertEquals($original->size(), $source->size());
		$this->assertSame($original->orientation(), $source->orientation());
	}

	public function testStripInPlace(): void
	{
		$darkroom = new Darkroom([]);
		$data     = file_get_contents(__DIR__ . '/fixtures/meta.jpg');
		$file     = $this->file('photo.jpg', $data);

		$darkroom->strip($file, $file);

		$this->assertSame(['photo.jpg'], $this->files());
		$this->assertLessThan(strlen($data), filesize($file));
	}

	#[RequiresFunction('exif_read_data')]
	public function testStripMeta(): void
	{
		$darkroom = new Darkroom([], ['meta' => ['location']]);
		$camera   = $this->file('camera.jpg');
		$place    = $this->file('place.jpg');

		$darkroom->strip(self::EXIF, $camera, ['camera']);
		$darkroom->strip(self::EXIF, $place);

		$camera = $darkroom->open($camera);
		$place  = $darkroom->open($place);

		$this->assertArrayHasKey('Make', $camera->exif()['IFD0']);
		$this->assertArrayNotHasKey('GPS', $camera->exif());
		$this->assertArrayHasKey('GPS', $place->exif());
		$this->assertArrayNotHasKey('Make', $place->exif()['IFD0']);
		$this->assertSame(Orientation::Rotate270, $place->orientation());
	}

	public function testStripMetaInvalid(): void
	{
		$darkroom = new Darkroom([]);
		$dst      = $this->file('photo.jpg');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid meta: \'colour\'');

		$darkroom->strip(self::EXIF, $dst, ['colour']);
	}

	public function testStripSource(): void
	{
		$darkroom = new Darkroom([]);
		$source   = $darkroom->open(self::PNG);
		$dst      = $this->file('photo.png');

		$darkroom->strip($source, $dst);

		$this->assertFileExists($dst);
	}

	public function testStripUnrecognised(): void
	{
		$darkroom = new Darkroom([]);
		$src      = $this->file('text.jpg', 'text');
		$dst      = $this->file('photo.jpg');

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('Unsupported or unreadable image: ' . $src);

		try {
			$darkroom->strip($src, $dst);
		} finally {
			$this->assertSame(['text.jpg'], $this->files());
		}
	}

	public function testTryPlan(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);

		$plan = $darkroom->tryPlan(self::JPEG, ['width' => 90]);

		$this->assertEquals(new Size(90, 60), $plan?->size());
	}

	public function testTryPlanInvalidOptions(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid width: 'abc'");

		$darkroom->tryPlan(self::JPEG, ['width' => 'abc']);
	}

	public function testTryPlanRefused(): void
	{
		$driver     = new FakeDriver();
		$limited    = new Darkroom([$driver], settings: ['limit' => 100]);
		$driverless = new Darkroom([]);
		$empty      = $this->file('empty.jpg', "\xFF\xD8\xFF\xE0");
		$text       = $this->file('text.jpg', 'text');

		$this->assertNull($limited->tryPlan($empty));
		$this->assertNull($limited->tryPlan($text));
		$this->assertNull($limited->tryPlan(self::JPEG));
		$this->assertNull($driverless->tryPlan(self::JPEG));
	}
}
