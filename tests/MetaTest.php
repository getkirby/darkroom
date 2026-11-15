<?php

namespace Darkroom\Tests;

use Darkroom\Meta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Meta::class)]
class MetaTest extends TestCase
{
	public function testCode(): void
	{
		$codes = array_map(fn (Meta $category) => $category->code(), Meta::cases());

		$this->assertSame('cam', Meta::Camera->code());
		$this->assertSame($codes, array_unique($codes));
	}

	public function testExif(): void
	{
		$keep = [Meta::Camera, Meta::Time];

		$tags = Meta::exif($keep);

		$this->assertContains(0x010F, $tags);
		$this->assertContains(0x0132, $tags);
		$this->assertContains(0x829A, $tags);
		$this->assertContains(0x9003, $tags);
		$this->assertNotContains(0x8298, $tags);
	}

	public function testExifNone(): void
	{
		$this->assertSame([], Meta::exif([]));
	}

	public static function keptProvider(): array
	{
		return [
			'all'   => [true, []],
			'none'  => [false, Meta::cases()],
			'empty' => [[], Meta::cases()],
			'list'  => [
				[Meta::Time, Meta::Camera],
				[Meta::Description, Meta::Location, Meta::Rights]
			],
			'every' => [Meta::cases(), []],
		];
	}

	#[DataProvider('keptProvider')]
	public function testKept(bool|array $strip, array $expected): void
	{
		$kept = Meta::kept($strip);

		$this->assertSame($expected, $kept);
	}

	public function testTags(): void
	{
		$gps = range(0, 0x1F);

		$this->assertSame([0x010E, 0xA436], Meta::Description->tags());
		$this->assertSame($gps, Meta::Location->tags());
		$this->assertSame([0x013B, 0x8298, 0xA437], Meta::Rights->tags());
	}

	public function testTagsWithoutOrientation(): void
	{
		foreach (Meta::cases() as $category) {
			$tags = $category->tags();

			$this->assertNotContains(0x0112, $tags);
		}
	}
}
