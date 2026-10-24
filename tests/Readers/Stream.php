<?php

namespace Darkroom\Tests\Readers;

final class Stream
{
	public $context;
	public int $read  = 0;
	public int $seeks = 0;

	protected string $data  = '';
	protected int $position = 0;

	public static function of($handle): self
	{
		return stream_get_meta_data($handle)['wrapper_data'];
	}

	public static function open(string $data)
	{
		if (in_array('darkroom', stream_get_wrappers(), true) === false) {
			stream_wrapper_register('darkroom', self::class);
		}

		$context = stream_context_create(['darkroom' => ['data' => $data]]);

		return fopen('darkroom://', 'rb', false, $context);
	}

	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->data);
	}

	public function stream_open(): bool
	{
		$this->data = stream_context_get_options($this->context)['darkroom']['data'];

		return true;
	}

	public function stream_read(int $count): string
	{
		$data = substr($this->data, $this->position, $count);

		$this->position += strlen($data);
		$this->read     += strlen($data);

		return $data;
	}

	public function stream_seek(int $offset, int $whence): bool
	{
		$this->seeks++;
		$this->position = match ($whence) {
			SEEK_CUR => $this->position + $offset,
			SEEK_END => strlen($this->data) + $offset,
			default  => $offset
		};

		return true;
	}

	public function stream_stat(): array
	{
		return ['size' => strlen($this->data)];
	}

	public function stream_tell(): int
	{
		return $this->position;
	}
}
