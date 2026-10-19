<?php

namespace Darkroom\Tests;

use Darkroom\Driver;
use Darkroom\Format;
use Darkroom\Source;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Driver::class)]
class DriverTest extends TestCase
{
	public function testCache(): void
	{
		$driver = new FakeDriver();
		$runs   = 0;
		$probe  = function () use (&$runs): string {
			$runs++;
			return 'result';
		};

		$this->assertSame('result', $driver->cache('a', $probe));
		$this->assertSame('result', $driver->cache('a', $probe));
		$this->assertSame(1, $runs);

		$this->assertSame('result', $driver->cache('b', $probe));
		$this->assertSame(2, $runs);
	}

	public function testCacheNull(): void
	{
		$driver = new FakeDriver();
		$runs   = 0;
		$probe  = function () use (&$runs): null {
			$runs++;
			return null;
		};

		$this->assertNull($driver->cache('a', $probe));
		$this->assertNull($driver->cache('a', $probe));
		$this->assertSame(1, $runs);
	}

	public function testCacheProbes(): void
	{
		$driver = new FakeDriver(['read' => [Format::Jpeg]]);

		$this->assertTrue($driver->canRead(Format::Jpeg));
		$this->assertTrue($driver->canRead(Format::Jpeg));
		$this->assertFalse($driver->canRead(Format::Png));
		$this->assertFalse($driver->canRead(Format::Png));
		$this->assertSame(['read:Jpeg', 'read:Png'], $driver->runs);
	}

	public function testConstruct(): void
	{
		$driver   = new FakeDriver();
		$settings = $driver->settings();

		$this->assertNull($settings['limit']);
		$this->assertSame(1, $settings['threads']);
	}

	public function testConstructSettings(): void
	{
		$driver = new FakeDriver([
			'threads' => 4,
			'limit'   => 1000,
			'quality' => 80,
		]);

		$settings = $driver->settings();

		$this->assertSame(4, $settings['threads']);
		$this->assertSame(1000, $settings['limit']);
		$this->assertArrayNotHasKey('quality', $settings);
	}

	public function testInfo(): void
	{
		$driver = new FakeDriver();

		$this->assertSame(['library' => null, 'version' => null], $driver->info());
	}

	public function testOrients(): void
	{
		$driver = new FakeDriver();
		$source = new Source(__DIR__ . '/fixtures/exif.jpg');

		$this->assertFalse($driver->orients($source));
	}
}
