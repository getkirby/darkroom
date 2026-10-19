<?php

namespace Darkroom\Tests;

use Darkroom\Focus;
use Darkroom\Gravity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Gravity::class)]
class GravityTest extends TestCase
{
	public static function focusProvider(): array
	{
		return [
			'top'          => [Gravity::Top, 0.5, 0.0],
			'top left'     => [Gravity::TopLeft, 0.0, 0.0],
			'top right'    => [Gravity::TopRight, 1.0, 0.0],
			'left'         => [Gravity::Left, 0.0, 0.5],
			'center'       => [Gravity::Center, 0.5, 0.5],
			'right'        => [Gravity::Right, 1.0, 0.5],
			'bottom'       => [Gravity::Bottom, 0.5, 1.0],
			'bottom left'  => [Gravity::BottomLeft, 0.0, 1.0],
			'bottom right' => [Gravity::BottomRight, 1.0, 1.0],
		];
	}

	#[DataProvider('focusProvider')]
	public function testFocus(
		Gravity $gravity,
		float $x,
		float $y
	): void {
		$this->assertEquals(new Focus($x, $y), $gravity->focus());
	}
}
