<?php

namespace Darkroom\Bench;

use Darkroom\Driver;
use Darkroom\Drivers\Gd;

final class GdBench extends DriverBench
{
	protected function driver(): Driver
	{
		return new Gd();
	}
}
