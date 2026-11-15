<?php

namespace Darkroom\Tests;

use Darkroom\Meta;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Meta::class)]
class MetaTest extends TestCase
{
	public function testExif(): void
	{
		$keep = [Meta::Camera, Meta::Color, Meta::Time];

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
		$this->assertSame([], Meta::exif([Meta::Color]));
	}

	public function testTags(): void
	{
		$gps = range(0, 0x1F);

		$this->assertSame([], Meta::Color->tags());
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
