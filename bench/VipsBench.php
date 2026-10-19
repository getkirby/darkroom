<?php

namespace Darkroom\Bench;

use Darkroom\Driver;
use Darkroom\Drivers\Vips;

final class VipsBench extends DriverBench
{
	protected function driver(): Driver
	{
		return new Vips();
	}
}
