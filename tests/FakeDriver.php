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

	public function cache(string $key, Closure $probe): mixed
	{
		return parent::cache($key, $probe);
	}

	public function canAnimate(Format $format): bool
	{
		return $this->probe('animate', $format);
	}

	public function canRead(Format|Source $input): bool
	{
		$format = $input instanceof Source ? $input->format() : $input;
		$file   = $input instanceof Source ? $input->file : null;

		return
			in_array($file, $this->settings['refuse'], true) === false &&
			$this->probe('read', $format) === true;
	}

	public function canWrite(Format $format): bool
	{
		return $this->probe('write', $format);
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

	public function isAvailable(): bool
	{
		return $this->settings['available'];
	}

	public function orients(Source $source): bool
	{
		return $this->settings['orients'];
	}

	private function probe(string $key, Format $format): bool
	{
		$name = $key . ':' . $format->name;

		return $this->cache($name, function () use ($key, $format, $name): bool {
			$this->runs[] = $name;
			return in_array($format, $this->settings[$key], true);
		});
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

	public function settings(): array
	{
		return $this->settings;
	}
}
