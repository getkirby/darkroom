<?php

namespace Darkroom\Tests;

use Darkroom\Format;
use Darkroom\Output;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Output::class)]
class OutputTest extends TestCase
{
	public function testConstruct(): void
	{
		$output = new Output(Format::Webp, 80, true, false);

		$this->assertSame(Format::Webp, $output->format);
		$this->assertSame(80, $output->quality);
		$this->assertTrue($output->interlace);
		$this->assertFalse($output->icc);
	}
}
