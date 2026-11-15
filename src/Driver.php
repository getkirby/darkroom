<?php

declare(strict_types = 1);

namespace Darkroom;

use Closure;

/**
 * @psalm-consistent-constructor
 */
abstract class Driver
{
	/**
	 * Samples of the formats whose decoders libheif loads as plugins:
	 * a white pixel from libvips 8.18 through libheif 1.23 and aom 3.15,
	 * a white 64×64 image through x265 4.3
	 */
	protected const array SAMPLES = [
		'Avif' => <<<'BASE64'
			AAAAHGZ0eXBhdmlmAAAAAG1pZjFhdmlmbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABw
			aWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAAA+gABAAAAAAAAAB8AAAAjaWlu
			ZgAAAAAAAQAAABVpbmZlAgAAAAABAABhdjAxAAAAAA5waXRtAAAAAAABAAAAVmlwcnAAAAA4aXBj
			bwAAAAxhdjFDgQAMAAAAABRpc3BlAAAAAAAAAAEAAAABAAAAEHBpeGkAAAAAAwgICAAAABZpcG1h
			AAAAAAAAAAEAAQOBAgMAAAAnbWRhdBIACggYAAaICGg0IDIRGUeHhiGAAGgAAJBAyRxgimo=
			BASE64,
		'Heic' => <<<'BASE64'
			AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAUJtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABw
			aWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAABZgABAAAAAAAAABYAAAAjaWlu
			ZgAAAAAAAQAAABVpbmZlAgAAAAABAABodmMxAAAAAA5waXRtAAAAAAABAAAAwmlwcnAAAACkaXBj
			bwAAAHhodmNDAQNwAAAAkAAAAAAAHvAA/P34+AAADwNgAAEAGEABDAH//wNwAAADAJAAAAMAAAMA
			HroCQGEAAQArQgEBA3AAAAMAkAAAAwAAAwAeoCCBBZbqSSmubgIaDAgAAAMAyAAAAwAIQGIAAQAH
			RAHBcrAiQAAAABRpc3BlAAAAAAAAAEAAAABAAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAA
			AAEAAQOBAgMAAAAebWRhdAAAABIoAa8TgObIaFPqH7pl35Rho5g=
			BASE64,
	];

	protected array $cache = [];
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
	 * Whether the library writes frames of the format and reads them back with their delays
	 */
	protected function animates(Format $format): bool
	{
		return false;
	}

	/**
	 * @template T
	 * @param Closure(): T $compute
	 * @return T
	 */
	protected function cache(string $key, Closure $compute): mixed
	{
		if (array_key_exists($key, $this->cache) === false) {
			$this->cache[$key] = $compute();
		}

		/** @var T */
		return $this->cache[$key];
	}

	final public function canAnimate(Format $format): bool
	{
		return $this->cache(
			'animate ' . $format->name,
			fn (): bool => $this->animates($format)
		);
	}

	final public function canRead(Format|Source $input): bool
	{
		$format  = $input instanceof Source ? $input->format() : $input;
		$decodes = $this->cache(
			'read ' . $format->name,
			fn (): bool => $this->decodes($format)
		);

		if ($decodes === false || $input instanceof Format) {
			return $decodes;
		}

		// no library maps PQ or HLG to sRGB
		return $input->isHdr() === false && $this->reads($input);
	}

	final public function canWrite(Format $format): bool
	{
		return $this->cache(
			'write ' . $format->name,
			fn (): bool => $this->encodes($format)
		);
	}

	abstract protected function decodes(Format $format): bool;

	protected function defaults(): array
	{
		return [];
	}

	abstract protected function encodes(Format $format): bool;

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
	 * Whether a profile describes RGB colors,
	 * by the color space in its header
	 */
	protected static function isRgb(string $profile): bool
	{
		return substr($profile, 16, 4) === 'RGB ';
	}

	/**
	 * Whether the library applies the source's orientation while loading
	 */
	public function orients(Source $source): bool
	{
		return false;
	}

	abstract public function process(Plan $plan, string $file): void;

	/**
	 * Whether the driver reads a source of a format it decodes
	 */
	protected function reads(Source $source): bool
	{
		return true;
	}
}
