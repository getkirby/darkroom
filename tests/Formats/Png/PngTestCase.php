<?php

namespace Darkroom\Tests\Formats\Png;

use Darkroom\Formats\Png\Reader;
use Darkroom\Formats\Png\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class PngTestCase extends FormatTestCase
{
	protected const string READER = Reader::class;
	protected const string WRITER = Writer::class;
}
