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
use Darkroom\Options;
use Darkroom\Settings;
use Darkroom\Size;
use Darkroom\Source;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

#[CoversClass(Darkroom::class)]
class DarkroomTest extends TestCase
{
	use Required;

	private const string GIF  = __DIR__ . '/fixtures/gif/animated.gif';
	private const string HEIC = __DIR__ . '/fixtures/heif/plain.heic';
	private const string JPEG = __DIR__ . '/fixtures/orientation/Landscape_1.jpg';
	private const string PNG  = __DIR__ . '/fixtures/png/plain.png';

	private function files(string $dir = ''): array
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
			'icc'       => [['icc' => 'flase'], 'Invalid icc: \'flase\''],
			'icc 2'     => [['icc' => 2], 'Invalid icc: 2'],
			'icc array' => [['icc' => []], 'Invalid icc: array'],
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
			'icc'       => 'no',
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

		$this->assertSame($vips, $darkroom->driver($still, Format::Png));
		$this->assertSame($next, $darkroom->driver($hdr, Format::Png));
	}

	public static function formatProvider(): array
	{
		$all  = Format::cases();
		$jpeg = [Format::Jpeg];
		$webp = [Format::Webp];

		return [
			'source'                => [self::JPEG, null, ['jpg', 'webp'], $all, Format::Jpeg],
			'source not allowed'    => [self::GIF, null, ['webp', 'jpg'], $all, Format::Webp],
			'source not writable'   => [self::PNG, null, ['png', 'webp'], $webp, Format::Webp],
			'in the given order'    => [self::GIF, null, ['jpg', 'webp'], $all, Format::Jpeg],
			'first not writable'    => [self::GIF, null, ['webp', 'jpg'], $jpeg, Format::Jpeg],
			'requested'             => [self::JPEG, 'avif', ['avif', 'jpg'], $all, Format::Avif],
			'requested as MIME'     => [self::JPEG, 'image/png', ['png'], $all, Format::Png],
			'requested as a format' => [self::JPEG, Format::Png, [Format::Png], $all, Format::Png],
			'allowed as MIME'       => [self::JPEG, null, ['image/jpeg'], $all, Format::Jpeg],
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

	public function testFormatNotAllowed(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver]);
		$source   = new Source(self::JPEG);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Output format not allowed: png');

		$darkroom->format($source, 'png', ['jpg', 'webp']);
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
			'requested not writable' => ['webp', ['jpg', 'webp'], [Format::Jpeg]],
			'nothing writable'       => [null, ['jpg', 'webp'], [Format::Png]],
			'nothing allowed'        => [null, [], Format::cases()],
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
			$this->assertEquals(new Size(90, 60), $plan->size);
			$this->assertSame(Format::Jpeg, $plan->format);
			$this->assertSame(90, $plan->quality);
		}

		$this->assertSame([], $driver->runs);
	}

	public function testPlanDefaults(): void
	{
		$defaults = ['quality' => '80', 'interlace' => 'on', 'icc' => 'off'];
		$darkroom = new Darkroom([], $defaults);

		$plan = $darkroom->plan(self::JPEG, []);

		$this->assertSame(80, $plan->quality);
		$this->assertTrue($plan->interlace);
		$this->assertFalse($plan->icc);
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

		$this->assertEquals(new Size(180, 120), $plan->size);

		// a crop larger than the image is capped at it
		$plan = $darkroom->process(self::JPEG, $dst, ['width' => 200, 'crop' => true]);

		$this->assertEquals(new Size(120, 120), $plan->size);
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

	public function testProcessNoLimit(): void
	{
		$driver   = new FakeDriver();
		$darkroom = new Darkroom([$driver], settings: ['limit' => null]);
		$dst      = $this->file('photo.png');

		// twice the default limit
		$png = static::bare(20000, 20000);
		$src = $this->file('large.png', $png);

		$plan = $darkroom->process($src, $dst, ['width' => 100]);

		$this->assertEquals(new Size(100, 100), $plan->size);
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

		$this->assertEquals(new Size(60, 60), $plan->size);
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
		$dst      = $this->file('photo.webp');
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
		$this->assertEquals(new Size(90, 60), $plan->size);
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
}
