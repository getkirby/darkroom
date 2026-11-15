<?php

namespace Darkroom\Tests;

use Darkroom\Key;
use Darkroom\Meta;
use Darkroom\Options;
use Darkroom\Plan;
use Darkroom\Rect;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Key::class)]
class KeyTest extends TestCase
{
	public static function forProvider(): array
	{
		$square = ['width' => 300, 'height' => 300];

		return [
			'whole image'      => [[], '1200x800-q85'],
			'resize'           => [['width' => 300], '300x200-q85'],
			'larger'           => [['width' => 2000], '1200x800-q85'],
			'crop'             => [[...$square, 'crop' => 'center'], '300x300-crop200-0-800-800-q85'],
			'crop left'        => [[...$square, 'crop' => 'left'], '300x300-crop0-0-800-800-q85'],
			'same crop'        => [[...$square, 'crop' => '10% 50%'], '300x300-crop0-0-800-800-q85'],
			'crop, same ratio' => [['width' => 600, 'height' => 400, 'crop' => true], '600x400-q85'],
			'filters'          => [
				['width' => 300, 'blur' => 5, 'grayscale' => true, 'sharpen' => 25],
				'300x200-blur5-sharpen25-grayscale-q85'
			],
			'encoding' => [
				['width' => 300, 'quality' => 80, 'interlace' => true, 'meta' => []],
				'300x200-q80-interlace-nometa'
			],
			'meta' => [
				['width' => 300, 'meta' => ['time', 'color', 'camera']],
				'300x200-q85-meta-camera-color-time'
			],
			'meta without color' => [
				['width' => 300, 'meta' => ['location']],
				'300x200-q85-meta-location'
			],
		];
	}

	#[DataProvider('forProvider')]
	public function testFor(array $options, string $key): void
	{
		$source  = $this->source(1200, 800);
		$options = Options::from($options);
		$plan    = Plan::for($source, $options);

		$for = Key::for($plan);

		$this->assertSame($key, $for->toString());
		$this->assertSame($key, (string)$for);
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'empty'          => [''],
			'no quality'     => ['300x200'],
			'other order'    => ['300x200-q90-blur5'],
			'half a crop'    => ['300x200-crop1-2-q90'],
			'unknown part'   => ['300x200-sepia-q90'],
			'trailing space' => ['300x200-q90 '],
			'unknown meta'   => ['300x200-q90-meta-colour'],
			'meta, no list'  => ['300x200-q90-meta'],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(string $key): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid key: ' . $key);

		Key::from($key);
	}

	public function testRoundTrip(): void
	{
		$sources = [[1200, 800], [4000, 2667], [200, 403], [1000, 333], [800, 1200]];
		$requests = [
			['width' => 300],
			['height' => 287],
			['width' => 142, 'height' => 287],
			['width' => 800, 'height' => 533],
			['width' => 300, 'height' => 300, 'crop' => 'center'],
			['width' => 700, 'height' => 300, 'crop' => 'top left'],
			['width' => 301, 'height' => 199, 'crop' => '70% 30%'],
			['width' => 120, 'crop' => 'bottom right'],
			['width' => 400, 'blur' => 5, 'grayscale' => true, 'sharpen' => 25],
			['width' => 400, 'quality' => 70, 'interlace' => true, 'meta' => []],
			['width' => 400, 'meta' => ['rights', 'color', 'description']],
		];

		foreach ($sources as $size) {
			$source = $this->source(...$size);

			foreach ($requests as $request) {
				$options = Options::from($request);
				$plan    = Plan::for($source, $options);
				$key     = Key::for($plan);

				$options = $key->toOptions();
				$again   = Plan::for($source, $options);
				$same    = Key::for($again);

				$this->assertEquals($key, $same);
				$this->assertEquals($plan->size(), $again->size());
				$this->assertEquals($plan->crop, $again->crop);
			}
		}
	}

	public static function toOptionsProvider(): array
	{
		$encoding = ['quality' => 90, 'interlace' => false, 'meta' => [Meta::Color]];

		return [
			'size' => [
				'1200x800-q90',
				['width' => 1200, 'height' => 800, ...$encoding]
			],
			'crop' => [
				'300x300-crop200-0-800-800-q90',
				['width' => 300, 'height' => 300, 'crop' => new Rect(200, 0, 800, 800), ...$encoding]
			],
			'filters' => [
				'300x200-blur5-sharpen25-grayscale-q90',
				['width' => 300, 'height' => 200, 'blur' => 5, 'sharpen' => 25, 'grayscale' => true, ...$encoding]
			],
			'encoding' => [
				'300x200-q80-interlace-nometa',
				['width' => 300, 'height' => 200, 'quality' => 80, 'interlace' => true, 'meta' => []]
			],
			'meta' => [
				'300x200-q90-meta-camera-color-location',
				['width' => 300, 'height' => 200, 'quality' => 90, 'interlace' => false, 'meta' => [Meta::Camera, Meta::Color, Meta::Location]]
			],
			'meta without color' => [
				'300x200-q90-meta-time',
				['width' => 300, 'height' => 200, 'quality' => 90, 'interlace' => false, 'meta' => [Meta::Time]]
			],
		];
	}

	#[DataProvider('toOptionsProvider')]
	public function testToOptions(string $key, array $expected): void
	{
		$expected = new Options(...$expected);
		$from     = Key::from($key);

		$options = $from->toOptions();

		$this->assertEquals($expected, $options);
	}
}
