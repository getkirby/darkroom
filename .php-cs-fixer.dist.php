<?php

return Kirby\PhpCs\Config::create()->setFinder(
	PhpCsFixer\Finder::create()
		->exclude([
			'playground/cache',
			'tests/fixtures',
			'tests/references',
			'vendor'
		])
		->in(__DIR__)
);
