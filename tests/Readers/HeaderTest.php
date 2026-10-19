<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Readers\Header;
use Darkroom\Size;
use Darkroom\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Header::class)]
class HeaderTest extends TestCase
{
	public function testConstruct(): void
	{
		$size   = new Size(1800, 1200);
		$header = new Header($size, Orientation::Rotate90, Format::Jpeg);

		$this->assertSame($size, $header->size);
		$this->assertSame(Orientation::Rotate90, $header->orientation);
		$this->assertSame(Format::Jpeg, $header->format);
	}

	public function testConstructEmpty(): void
	{
		$header = new Header();

		$this->assertEquals(new Size(0, 0), $header->size);
		$this->assertSame(Orientation::None, $header->orientation);
		$this->assertNull($header->format);
	}
}
