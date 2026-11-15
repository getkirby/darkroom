<?php

namespace Darkroom\Tests;

use Closure;
use Darkroom\Driver;
use Darkroom\Format;
use Darkroom\Plan;
use Darkroom\Source;

final class FakeDriver extends Driver
{
	public array $files = [];
	public array $plans = [];
	public array $runs = [];

	protected function animates(Format $format): bool
	{
		return $this->probe('animate', $format);
	}

	public function cache(string $key, Closure $compute): mixed
	{
		return parent::cache($key, $compute);
	}

	protected function decodes(Format $format): bool
	{
		return $this->probe('read', $format);
	}

	protected function defaults(): array
	{
		return [
			'animate'   => [],
			'available' => true,
			'fatal'     => false,
			'limit'     => null,
			'orients'   => false,
			'read'      => Format::cases(),
			'refuse'    => [],
			'threads'   => 1,
			'throw'     => null,
			'write'     => Format::cases(),
		];
	}

	protected function encodes(Format $format): bool
	{
		return $this->probe('write', $format);
	}

	public function isAvailable(): bool
	{
		return $this->settings['available'];
	}

	public function orients(Source $source): bool
	{
		return $this->settings['orients'];
	}

	protected function probe(string $key, Format $format): bool
	{
		$this->runs[] = $key . ':' . $format->name;

		return in_array($format, $this->settings[$key], true);
	}

	public function process(Plan $plan, string $file): void
	{
		$this->plans[] = $plan;
		$this->files[] = $file;

		file_put_contents($file, $plan->format->name);

		if ($this->settings['fatal'] === true) {
			str_repeat('x', PHP_INT_MAX);
		}

		if ($this->settings['throw'] !== null) {
			throw $this->settings['throw'];
		}
	}

	protected function reads(Source $source): bool
	{
		return in_array($source->file, $this->settings['refuse'], true) === false;
	}

	public function settings(): array
	{
		return $this->settings;
	}
}
