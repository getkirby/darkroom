<?php

namespace Darkroom\Tests\Containers;

use Darkroom\Containers\FullBox;
use Darkroom\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FullBox::class)]
class FullBoxTest extends TestCase
{
	public function testEmpty(): void
	{
		$box = new FullBox('');

		$this->assertSame(0, $box->version);
		$this->assertSame(0, $box->flags());
		$this->assertSame(0, $box->id());
		$this->assertSame('', $box->bytes(4));
	}

	public function testField(): void
	{
		$this->assertSame('', FullBox::field(0, 0));
		$this->assertSame("\x01\x02", FullBox::field(0x0102, 2));
		$this->assertSame("\0\0\x01\x02", FullBox::field(0x0102, 4));
		$this->assertSame("\0\0\0\0\0\0\x01\x02", FullBox::field(0x0102, 8));

		// too large, negative, a width without a format
		$this->assertNull(FullBox::field(1, 0));
		$this->assertNull(FullBox::field(0x10000, 2));
		$this->assertNull(FullBox::field(-1, 4));
		$this->assertNull(FullBox::field(1, 1));
	}

	public function testFields(): void
	{
		$box = new FullBox("\x01\0\x01\x03" . pack('N', 7) . 'ab');

		$this->assertSame(1, $box->version);
		$this->assertSame(0x0103, $box->flags());
		$this->assertSame(4, $box->width());
		$this->assertSame(7, $box->id());
		$this->assertSame(2, $box->left());
		$this->assertSame('ab', $box->bytes(2));
		$this->assertSame(0, $box->int(4));
	}

	public function testVersion(): void
	{
		$box = new FullBox("\0\0\0\0" . pack('n', 7));

		$this->assertSame(2, $box->width());
		$this->assertSame(7, $box->id());
		$this->assertSame(0, $box->left());
	}

	public function testWidth(): void
	{
		$first  = new FullBox("\x01\0\0\0");
		$second = new FullBox("\x02\0\0\0");

		$this->assertSame(4, $first->width());
		$this->assertSame(2, $first->width(2));
		$this->assertSame(4, $second->width(2));
		$this->assertSame(2, $second->width(3));
	}
}
