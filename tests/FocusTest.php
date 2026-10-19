<?php

namespace Darkroom\Tests;

use Darkroom\Focus;
use Darkroom\Gravity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Focus::class)]
class FocusTest extends TestCase
{
	public function testConstruct(): void
	{
		$focus = new Focus(0.7, 0.3);

		$this->assertSame(0.7, $focus->x);
		$this->assertSame(0.3, $focus->y);
	}

	public static function fromProvider(): array
	{
		return [
			'percentages' => ['70% 30%', 0.7, 0.3],
			'right'       => ['right', 1.0, 0.5],
			'bottom'      => ['bottom', 0.5, 1.0],
			'bottom left' => ['bottom left', 0.0, 1.0],
			'gravity'     => [Gravity::TopLeft, 0.0, 0.0],

			'comma'           => ['70%, 30%', 0.7, 0.3],
			'comma, no space' => ['70%,30%', 0.7, 0.3],
			'decimals'        => ['70.4%,30.4%', 0.704, 0.304],

			'1%'   => ['1% 50%', 0.01, 0.5],
			'1.0%' => ['1.0% 50.0%', 0.01, 0.5],
			'0.5%' => ['0.5% 50%', 0.005, 0.5],

			'edges'      => ['0% 100%', 0.0, 1.0],
			'rounded'    => ['33.33% 66.67%', 0.333, 0.667],
			'clamped'    => ['150% -10%', 1.0, 0.0],
			'above 100%' => ['100.5% 50%', 1.0, 0.5],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(
		string|Gravity $value,
		float $x,
		float $y
	): void {
		$this->assertEquals(new Focus($x, $y), Focus::from($value));
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'unknown'  => ['middle'],
			'reversed' => ['left top'],
			'json'     => ['{"x":0.7,"y":0.3}'],

			'no %'           => ['70,30'],
			'fractions'      => ['0.7, 0.3'],
			'decimals, no %' => ['70.4,30.4'],

			'empty'            => [''],
			'upper case'       => ['Top Left'],
			'leading space'    => [' center'],
			'one value'        => ['50%'],
			'three values'     => ['50% 50% 50%'],
			'space before %'   => ['50 % 50 %'],
			'trailing newline' => ["50% 50%\n"],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(string $value): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid focus: ' . $value);

		Focus::from($value);
	}
}
