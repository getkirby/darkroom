<?php

namespace Darkroom\Tests;

use Darkroom\Defaults;
use Darkroom\Format;
use Darkroom\Meta;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Defaults::class)]
class DefaultsTest extends TestCase
{
	public function testConstruct(): void
	{
		$defaults = new Defaults();

		$this->assertNull($defaults->quality);
		$this->assertNull($defaults->format);
		$this->assertNull($defaults->interlace);
		$this->assertNull($defaults->meta);
	}

	public static function fromProvider(): array
	{
		return [
			'none'       => [[], null, null, null, null],
			'clamped'    => [['quality' => 150], 100, null, null, null],
			'other keys' => [['threads' => 4, 'width' => 100], null, null, null, null],
			'set' => [
				['quality' => 20, 'format' => 'webp', 'interlace' => true, 'meta' => ['time', Meta::Camera]],
				20,
				Format::Webp,
				true,
				[Meta::Camera, Meta::Time]
			],
			'strings' => [
				['quality' => '80', 'format' => 'JPEG', 'interlace' => 'yes', 'meta' => ['color', 'color']],
				80,
				Format::Jpeg,
				true,
				[Meta::Color]
			],
			'false' => [
				['quality' => false, 'format' => false, 'interlace' => false, 'meta' => []],
				null,
				null,
				false,
				[]
			],
			'not set' => [
				['quality' => 0, 'format' => null, 'interlace' => null, 'meta' => null],
				null,
				null,
				null,
				null
			],
			'empty strings' => [
				['quality' => '', 'format' => '', 'interlace' => '', 'meta' => ''],
				null,
				null,
				null,
				null
			],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(
		array $values,
		int|null $quality,
		Format|null $format,
		bool|null $interlace,
		array|null $meta
	): void {
		$defaults = Defaults::from($values);

		$this->assertSame($quality, $defaults->quality);
		$this->assertSame($format, $defaults->format);
		$this->assertSame($interlace, $defaults->interlace);
		$this->assertSame($meta, $defaults->meta);
	}

	public function testFromDefaults(): void
	{
		$defaults = new Defaults(quality: 80);

		$this->assertSame($defaults, Defaults::from($defaults));
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'quality'   => [['quality' => 'high'], 'Invalid quality: \'high\''],
			'negative'  => [['quality' => -1], 'Invalid quality: -1'],
			'format'    => [['format' => 'svg'], 'Unknown image format: svg'],
			'interlace' => [['interlace' => 'flase'], 'Invalid interlace: \'flase\''],
			'meta'      => [['meta' => 2], 'Invalid meta: 2'],
			'meta name' => [['meta' => ['colour']], 'Invalid meta: \'colour\''],
			'meta type' => [['meta' => [true]], 'Invalid meta: true'],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(array $values, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		Defaults::from($values);
	}

	public function testToArray(): void
	{
		$defaults = new Defaults(
			quality:   80,
			format:    Format::Webp,
			interlace: false,
			meta:      [Meta::Camera]
		);

		$array = $defaults->toArray();
		$back  = Defaults::from($array);

		$this->assertSame([
			'quality'   => 80,
			'format'    => 'webp',
			'interlace' => false,
			'meta'      => ['camera'],
		], $array);

		$this->assertEquals($defaults, $back);
	}

	public function testToArrayWithoutDefaults(): void
	{
		$defaults = new Defaults();

		$this->assertSame([], $defaults->toArray());
	}
}
