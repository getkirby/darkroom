<?php

namespace Darkroom\Bench;

use Darkroom\Driver;
use Darkroom\Drivers\Imagick;

final class ImagickBench extends DriverBench
{
	protected function driver(): Driver
	{
		return new Imagick();
	}
}
