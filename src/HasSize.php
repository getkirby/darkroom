<?php

declare(strict_types = 1);

namespace Darkroom;

trait HasSize
{
	public function height(): int
	{
		return $this->size()->height;
	}

	public function isLandscape(): bool
	{
		return $this->size()->isLandscape();
	}

	public function isPortrait(): bool
	{
		return $this->size()->isPortrait();
	}

	public function isSquare(): bool
	{
		return $this->size()->isSquare();
	}

	public function ratio(): float
	{
		return $this->size()->ratio();
	}

	abstract public function size(): Size;

	public function width(): int
	{
		return $this->size()->width;
	}
}
