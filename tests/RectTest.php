<?php

namespace Darkroom\Tests;

use Darkroom\Rect;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Rect::class)]
class RectTest extends TestCase
{
	public function testConstruct(): void
	{
		$rect = new Rect(10, 20, 300, 200);

		$this->assertSame(10, $rect->x);
		$this->assertSame(20, $rect->y);
		$this->assertSame(300, $rect->width);
		$this->assertSame(200, $rect->height);
	}
}
