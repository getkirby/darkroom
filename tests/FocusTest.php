<?php

namespace Darkroom\Tests;

use Darkroom\Focus;
use Darkroom\Gravity;
use Darkroom\Rect;
use Darkroom\Size;
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
		string $value,
		float $x,
		float $y
	): void {
		$this->assertEquals(new Focus($x, $y), Focus::from($value));
	}

	public function testFromGravity(): void
	{
		$this->assertEquals(new Focus(0.0, 0.0), Focus::from(Gravity::TopLeft));
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

	public static function placeProvider(): array
	{
		return [
			'center'       => [[0.5, 0.5], [800, 800], [1200, 800], [200, 0, 800, 800]],
			'off center'   => [[0.4, 0.5], [800, 800], [1200, 800], [80, 0, 800, 800]],
			'top left'     => [[0.0, 0.0], [800, 800], [1200, 800], [0, 0, 800, 800]],
			'bottom right' => [[1.0, 1.0], [800, 800], [1200, 800], [400, 0, 800, 800]],
			'at the edge'  => [[0.9, 0.5], [800, 800], [1200, 800], [400, 0, 800, 800]],
			'vertical'     => [[0.5, 0.3], [1200, 400], [1200, 800], [0, 40, 1200, 400]],
			'rounded'      => [[0.5, 0.5], [3, 3], [10, 10], [4, 4, 3, 3]],
			'whole canvas' => [[0.7, 0.3], [1200, 800], [1200, 800], [0, 0, 1200, 800]],
		];
	}

	#[DataProvider('placeProvider')]
	public function testPlace(
		array $focus,
		array $size,
		array $canvas,
		array $expected
	): void {
		$focus  = new Focus(...$focus);
		$size   = new Size(...$size);
		$canvas = new Size(...$canvas);

		$rect = $focus->place($size, $canvas);

		$this->assertEquals(new Rect(...$expected), $rect);
	}

	public static function toStringProvider(): array
	{
		return [
			'center'   => [0.5, 0.5, '50% 50%'],
			'edges'    => [0.0, 1.0, '0% 100%'],
			'decimals' => [0.704, 0.304, '70.4% 30.4%'],
			'fine'     => [0.005, 0.333, '0.5% 33.3%'],
		];
	}

	#[DataProvider('toStringProvider')]
	public function testToString(
		float $x,
		float $y,
		string $string
	): void {
		$focus = new Focus($x, $y);

		$this->assertSame($string, (string)$focus);
		$this->assertEquals($focus, Focus::from($string));
	}

	#[DataProvider('fromProvider')]
	public function testTryFrom(
		string $value,
		float $x,
		float $y
	): void {
		$this->assertEquals(new Focus($x, $y), Focus::tryFrom($value));
	}

	#[DataProvider('fromInvalidProvider')]
	public function testTryFromInvalid(string $value): void
	{
		$this->assertNull(Focus::tryFrom($value));
	}

	public function testTryFromNull(): void
	{
		$this->assertNull(Focus::tryFrom(null));
	}
}
