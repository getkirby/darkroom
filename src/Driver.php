<?php

namespace Darkroom;

use Closure;

/**
 * @psalm-consistent-constructor
 */
abstract class Driver
{
	protected array $probes = [];
	protected readonly array $settings;

	public function __construct(array $settings = [])
	{
		$defaults       = $this->defaults();
		$this->settings = [
			...$defaults,
			...array_intersect_key($settings, $defaults)
		];
	}

	/**
	 * @template T
	 * @param Closure(): T $probe
	 * @return T
	 */
	protected function cache(string $key, Closure $probe): mixed
	{
		if (array_key_exists($key, $this->probes) === false) {
			$this->probes[$key] = $probe();
		}

		/** @var T */
		return $this->probes[$key];
	}

	abstract public function canAnimate(Format $format): bool;

	abstract public function canRead(Format|Source $input): bool;

	abstract public function canWrite(Format $format): bool;

	protected function defaults(): array
	{
		return [];
	}

	/**
	 * @return array{library: string|null, version: string|null}
	 */
	public function info(): array
	{
		return [
			'library' => null,
			'version' => null,
		];
	}

	abstract public function isAvailable(): bool;

	/**
	 * Whether the library applies the source's orientation while loading
	 */
	public function orients(Source $source): bool
	{
		return false;
	}

	abstract public function process(Plan $plan, string $file): void;
}
