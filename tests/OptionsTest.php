<?php

namespace Darkroom\Tests;

use Darkroom\Defaults;
use Darkroom\Focus;
use Darkroom\Format;
use Darkroom\Gravity;
use Darkroom\Options;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Options::class)]
class OptionsTest extends TestCase
{
	public function testConstruct(): void
	{
		$options = new Options();

		$this->assertNull($options->width);
		$this->assertNull($options->height);
		$this->assertNull($options->crop);
		$this->assertNull($options->blur);
		$this->assertFalse($options->grayscale);
		$this->assertNull($options->sharpen);
		$this->assertNull($options->quality);
		$this->assertNull($options->format);
		$this->assertNull($options->interlace);
		$this->assertNull($options->icc);
	}

	public static function focusProvider(): array
	{
		return [
			'center'       => ['center', 0.5, 0.5],
			'top left'     => ['top left', 0.0, 0.0],
			'bottom right' => ['bottom right', 1.0, 1.0],
			'percentages'  => ['70% 30%', 0.7, 0.3],
			'comma'        => ['70%, 30%', 0.7, 0.3],
			'clamped'      => ['150% -10%', 1.0, 0.0],
			'huge'         => ['100000000000000005366162204393472% 0%', 1.0, 0.0],
		];
	}

	#[DataProvider('focusProvider')]
	public function testFocus(
		string $crop,
		float $x,
		float $y
	): void {
		$options = new Options(crop: $crop);

		$this->assertEquals(new Focus($x, $y), $options->focus());
	}

	public function testFocusInvalid(): void
	{
		$options = new Options(crop: 'middle');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid focus: middle');

		$options->focus();
	}

	public function testFocusWithoutCrop(): void
	{
		$options = new Options(width: 300);

		$this->assertNull($options->focus());
	}

	public static function fromProvider(): array
	{
		$keys    = ['width', 'height', 'crop', 'blur', 'grayscale', 'sharpen', 'quality', 'format'];
		$numbers = ['width', 'height', 'blur', 'sharpen', 'quality'];

		return [
			'nothing' => [[], []],
			'all' => [
				[
					'width'     => 300,
					'height'    => 200,
					'crop'      => 'top left',
					'blur'      => 5,
					'grayscale' => true,
					'sharpen'   => 25,
					'quality'   => 80,
					'format'    => 'webp',
					'interlace' => true,
				],
				[
					'width'     => 300,
					'height'    => 200,
					'crop'      => 'top left',
					'blur'      => 5,
					'grayscale' => true,
					'sharpen'   => 25,
					'quality'   => 80,
					'format'    => Format::Webp,
					'interlace' => true,
				]
			],

			'numeric strings' => [
				[
					'width'   => '300',
					'height'  => '200',
					'blur'    => '5',
					'sharpen' => '25',
					'quality' => '80',
				],
				[
					'width'   => 300,
					'height'  => 200,
					'blur'    => 5,
					'sharpen' => 25,
					'quality' => 80,
				]
			],
			'signs and spaces' => [
				['width' => ' 300', 'height' => '+200'],
				['width' => 300, 'height' => 200]
			],
			'whole floats' => [
				['width' => 300.0, 'blur' => 90.0, 'quality' => 90.0],
				['width' => 300, 'blur' => 90, 'quality' => 90]
			],

			'null'         => [array_fill_keys([...$keys, 'interlace'], null), []],
			'empty string' => [array_fill_keys([...$keys, 'interlace'], ''), []],
			'false'        => [array_fill_keys($keys, false), []],
			'0'            => [array_fill_keys($keys, 0), []],
			'0 as string'  => [array_fill_keys($numbers, '0'), []],
			'one side'     => [['width' => 0, 'height' => 200], ['height' => 200]],

			'above the range' => [
				['blur' => 150, 'sharpen' => 101, 'quality' => '150'],
				['blur' => 100, 'sharpen' => 100, 'quality' => 100]
			],
			'smallest' => [
				['width' => 1, 'height' => 1, 'blur' => 1, 'sharpen' => 1, 'quality' => 1],
				['width' => 1, 'height' => 1, 'blur' => 1, 'sharpen' => 1, 'quality' => 1]
			],
			'large size' => [['width' => 100000], ['width' => 100000]],

			'crop true'    => [['crop' => true], ['crop' => 'center']],
			'blur true'    => [['blur' => true], ['blur' => 10]],
			'sharpen true' => [['sharpen' => true], ['sharpen' => 50]],

			'top'          => [['crop' => 'top'], ['crop' => 'top']],
			'top left'     => [['crop' => 'top left'], ['crop' => 'top left']],
			'top right'    => [['crop' => 'top right'], ['crop' => 'top right']],
			'left'         => [['crop' => 'left'], ['crop' => 'left']],
			'center'       => [['crop' => 'center'], ['crop' => 'center']],
			'right'        => [['crop' => 'right'], ['crop' => 'right']],
			'bottom'       => [['crop' => 'bottom'], ['crop' => 'bottom']],
			'bottom left'  => [['crop' => 'bottom left'], ['crop' => 'bottom left']],
			'bottom right' => [['crop' => 'bottom right'], ['crop' => 'bottom right']],
			'percentages'  => [['crop' => '70% 30%'], ['crop' => '70% 30%']],
			'comma'        => [['crop' => '70%, 30%'], ['crop' => '70%, 30%']],
			'decimals'     => [['crop' => '30.5% 70.25%'], ['crop' => '30.5% 70.25%']],
			'off canvas'   => [['crop' => '150% -10%'], ['crop' => '150% -10%']],
			'gravity'      => [['crop' => Gravity::TopLeft], ['crop' => 'top left']],

			'greyscale'         => [['greyscale' => true], ['grayscale' => true]],
			'bw'                => [['bw' => true], ['grayscale' => true]],
			'grayscale over bw' => [['grayscale' => false, 'bw' => true], []],
			'greyscale over bw' => [['greyscale' => true, 'bw' => false], ['grayscale' => true]],
			'grayscale, null'   => [['grayscale' => null, 'bw' => true], ['grayscale' => true]],
			'grayscale, empty'  => [['grayscale' => '', 'bw' => true], []],

			'grayscale "true"'  => [['grayscale' => 'true'], ['grayscale' => true]],
			'grayscale "1"'     => [['grayscale' => '1'], ['grayscale' => true]],
			'grayscale "yes"'   => [['grayscale' => 'yes'], ['grayscale' => true]],
			'grayscale 1'       => [['grayscale' => 1], ['grayscale' => true]],
			'grayscale "false"' => [['grayscale' => 'false'], []],
			'grayscale "0"'     => [['grayscale' => '0'], []],
			'grayscale "off"'   => [['grayscale' => 'off'], []],
			'interlace true'    => [['interlace' => true], ['interlace' => true]],
			'interlace "false"' => [['interlace' => 'false'], ['interlace' => false]],
			'interlace "1"'     => [['interlace' => '1'], ['interlace' => true]],

			// interlace is off, not unset, so it overrides the settings
			'interlace false' => [['interlace' => false], ['interlace' => false]],
			'interlace 0'     => [['interlace' => 0], ['interlace' => false]],
			'interlace "off"' => [['interlace' => 'off'], ['interlace' => false]],
			'interlace ""'    => [['interlace' => ''], []],

			'format'           => [['format' => Format::Avif], ['format' => Format::Avif]],
			'format extension' => [['format' => 'jpeg'], ['format' => Format::Jpeg]],
			'format mime'      => [['format' => 'image/png'], ['format' => Format::Png]],
			'format case'      => [['format' => 'WEBP'], ['format' => Format::Webp]],

			'unknown keys' => [
				[
					'filename'     => 'photo.jpg',
					'scaleWidth'   => 0.5,
					'scaleHeight'  => 0.5,
					'sourceWidth'  => 1200,
					'sourceHeight' => 800,
					'threads'      => 4,
					'width'        => 600,
				],
				['width' => 600]
			],
			'every key spelled out' => [
				[
					'blur'        => false,
					'crop'        => false,
					'format'      => null,
					'grayscale'   => false,
					'height'      => null,
					'quality'     => 90,
					'scaleHeight' => null,
					'scaleWidth'  => null,
					'sharpen'     => null,
					'width'       => null,
				],
				['quality' => 90]
			],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(array $values, array $expected): void
	{
		$expected = new Options(...$expected);

		$options = Options::from($values);

		// compared as arrays, so `false` and `null` differ
		$this->assertSame((array)$expected, (array)$options);
	}

	public function testFromDefaults(): void
	{
		$defaults = new Defaults(quality: 80, format: Format::Webp);
		$options  = Options::from($defaults);

		$this->assertInstanceOf(Options::class, $options);
		$this->assertSame(['quality' => 80, 'format' => 'webp'], $options->toArray());
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'width, text'     => [['width' => 'abc'], "Invalid width: 'abc'"],
			'width, unit'     => [['width' => '300px'], "Invalid width: '300px'"],
			'width, decimal'  => [['width' => '300.0'], "Invalid width: '300.0'"],
			'width, exponent' => [['width' => '1e3'], "Invalid width: '1e3'"],
			'width, negative' => [['width' => -5], 'Invalid width: -5'],
			'width, "-5"'     => [['width' => '-5'], "Invalid width: '-5'"],
			'width, true'     => [['width' => true], 'Invalid width: true'],

			'height, float' => [['height' => 1.5], 'Invalid height: 1.5'],
			'height, "1.5"' => [['height' => '1.5'], "Invalid height: '1.5'"],

			'blur, text'     => [['blur' => 'x'], "Invalid blur: 'x'"],
			'blur, negative' => [['blur' => -1], 'Invalid blur: -1'],

			'sharpen, array' => [['sharpen' => [50]], 'Invalid sharpen: array'],

			'quality, true'     => [['quality' => true], 'Invalid quality: true'],
			'quality, negative' => [['quality' => -1], 'Invalid quality: -1'],
			'quality, text'     => [['quality' => 'high'], "Invalid quality: 'high'"],

			'grayscale, text' => [['grayscale' => 'x'], "Invalid grayscale: 'x'"],
			'bw, 2'           => [['bw' => 2], 'Invalid grayscale: 2'],

			'interlace, text' => [
				['interlace' => 'progressive'],
				"Invalid interlace: 'progressive'"
			],

			'format, unknown' => [['format' => 'svg'], 'Unknown image format: svg'],
			'format, number'  => [['format' => 5], 'Invalid format: 5'],
			'format, true'    => [['format' => true], 'Invalid format: true'],

			'crop, unknown'    => [['crop' => 'middle'], 'Invalid focus: middle'],
			'crop, upper case' => [['crop' => 'Top Left'], 'Invalid focus: Top Left'],
			'crop, no %'       => [['crop' => '70,30'], 'Invalid focus: 70,30'],
			'crop, "0"'        => [['crop' => '0'], 'Invalid focus: 0'],
			'crop, number'     => [['crop' => 5], 'Invalid crop: 5'],
			'crop, json'       => [
				['crop' => '{"x":0.7,"y":0.3}'],
				'Invalid focus: {"x":0.7,"y":0.3}'
			],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(array $values, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		Options::from($values);
	}

	public function testFromOptions(): void
	{
		$options = new Options(width: 300);

		$this->assertSame($options, Options::from($options));
	}

	public function testToArray(): void
	{
		$options = new Options(
			width:     300,
			height:    200,
			crop:      'top left',
			blur:      5,
			grayscale: true,
			sharpen:   25,
			quality:   80,
			format:    Format::Jpeg,
			interlace: false,
			icc:       false
		);

		$array = $options->toArray();
		$back  = Options::from($array);

		$this->assertSame([
			'quality'   => 80,
			'format'    => 'jpg',
			'interlace' => false,
			'icc'       => false,
			'width'     => 300,
			'height'    => 200,
			'crop'      => 'top left',
			'blur'      => 5,
			'grayscale' => true,
			'sharpen'   => 25,
		], $array);

		$this->assertSame((array)$options, (array)$back);
	}

	public function testToArrayWithoutOptions(): void
	{
		$empty  = new Options();
		$color  = new Options(grayscale: false);
		$height = new Options(height: 200);

		$this->assertSame([], $empty->toArray());
		$this->assertSame([], $color->toArray());
		$this->assertSame(['height' => 200], $height->toArray());
	}
}
