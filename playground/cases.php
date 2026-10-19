<?php

use Darkroom\Format;

$formats = [];

foreach (Format::cases() as $format) {
	$formats['to-' . $format->extension()] = [
		'width'  => 300,
		'format' => $format->extension()
	];
}

return [
	'resize' => [
		'width'  => ['width' => 300],
		'height' => ['height' => 200],
		'box'    => ['width' => 300, 'height' => 300],
	],
	'crop' => [
		'center' => ['width' => 200, 'height' => 200, 'crop' => 'center'],
		'focus'  => ['width' => 200, 'height' => 200, 'crop' => '20% 80%'],
	],
	'effects' => [
		'grayscale' => ['width' => 300, 'grayscale' => true],
		'blur'      => ['width' => 300, 'blur' => 5],
		'sharpen'   => ['width' => 300, 'sharpen' => 50],
	],
	'formats' => $formats,
	'quality' => [
		'q20-jpg'  => ['width' => 300, 'quality' => 20, 'format' => 'jpg'],
		'q20-webp' => ['width' => 300, 'quality' => 20, 'format' => 'webp'],
		'q20-avif' => ['width' => 300, 'quality' => 20, 'format' => 'avif'],
	],
	'frames' => [
		'frames-crop' => ['width' => 150, 'height' => 150, 'crop' => 'center'],
		// the formats that can animate
		'frames-gif'  => ['width' => 200, 'format' => 'gif'],
		'frames-jxl'  => ['width' => 200, 'format' => 'jxl'],
		'frames-webp' => ['width' => 200, 'format' => 'webp'],
	],
];
