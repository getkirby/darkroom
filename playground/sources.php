<?php

$card   = ['resize', 'crop'];
$photos = ['resize', 'effects', 'formats', 'quality'];

// CC0 photos from Wikimedia Commons, taken down to 1600 px
$commons = ', CC0, commons.wikimedia.org; magick -resize 1600x1600 -strip, its profile kept, -quality 92';

return [
	'card.jpg' => [
		'text'   => 'The test card. Edges: top red, right green, bottom blue, left yellow. ' .
			'The circle turns into an ellipse when the ratio is off.',
		'made'   => 'Drawn by ImageMagick 7 at 900×600: a 10 % grid, 2 % edges, black corners labelled ' .
			'in Arial Bold, 1 and 2 px lines, grey and hue ramps, eight color patches, an arrow in a circle; ' .
			'-quality 85',
		'groups' => ['resize', 'crop', 'effects', 'formats', 'quality'],
	],
	'portrait.jpg' => [
		'text'   => 'The card upright: crops cut its top and bottom.',
		'made'   => 'The card drawn at 400×600, -quality 85',
		'groups' => $card,
	],
	'panorama.jpg' => [
		'text'   => 'The card at 6:1.',
		'made'   => 'The card drawn at 2400×400, -quality 80',
		'groups' => $card,
	],
	'photo.jpg' => [
		'text'   => 'Clouds with grain, where compression and filters show.',
		'made'   => 'magick -seed 7 -size 900x600 plasma:fractal -blur 0x2 -modulate 100,130 ' .
			'-attenuate 0.4 +noise Gaussian -quality 85',
		'groups' => ['effects', 'quality'],
	],
	'headwrap.jpg' => [
		'text'   => 'A portrait in Adobe RGB: dark skin tones and a soft background, ' .
			'where banding and blocks show first.',
		'made'   => '"Woman in a headwrap in Quebec City" by Wilfredor' . $commons,
		'groups' => $photos,
	],
	'sunset.jpg' => [
		'text'   => 'A smooth sky over the sea, where banding shows.',
		'made'   => '"Juan Griego Sunset" by Wilfredor' . $commons,
		'groups' => $photos,
	],
	'moss.jpg' => [
		'text'   => 'Leaves and moss: fine detail that resizing blurs and compression smears.',
		'made'   => '"Wood sorrel and haircap moss" by W.carter' . $commons,
		'groups' => $photos,
	],
	'neon.jpg' => [
		'text'   => 'Neon signs at night: saturated colors and small text, ' .
			'where chroma at half resolution bleeds.',
		'made'   => '"Hong Kong in the night" by Wilfredor' . $commons,
		'groups' => $photos,
	],
	'orientation-6.jpg' => [
		'text'   => 'The card stored on its side, turned upright by EXIF orientation 6.',
		'made'   => 'The card at 450×300, -rotate 270 -strip -quality 85, then exiftool -Orientation#=6',
		'groups' => $card,
	],
	'irot.avif' => [
		'text'   => 'AVIF stored on its side, turned upright by its irot box.',
		'made'   => 'The card at 600×400, -rotate 90, then avifenc -q 60 --irot 1',
		'groups' => $card,
	],
	'clap.avif' => [
		'text'   => 'AVIF whose clean aperture cuts off a magenta border: ' .
			'no magenta may show.',
		'made'   => 'The card at 600×400, -bordercolor magenta -border 20, ' .
			'then avifenc -q 60 -y 444 --crop 20,20,600,400',
		'groups' => $card,
	],
	'card.heic' => [
		'text'   => 'The card as HEIC.',
		'made'   => 'The card at 600×400, heif-enc -q 60',
		'groups' => $card,
	],
	'card.jxl' => [
		'text'   => 'The card as JPEG XL.',
		'made'   => 'The card at 600×400, cjxl -q 80',
		'groups' => $card,
	],
	'cmyk.jpg' => [
		'text'   => 'CMYK JPEG without a profile: colors must match the card.',
		'made'   => 'The card at 600×400, -colorspace CMYK -quality 85',
		'groups' => ['resize', 'formats'],
	],
	'alpha.png' => [
		'text'   => 'A soft shadow and a fading band on transparency; ' .
			'formats without alpha fill it.',
		'made'   => 'Drawn by ImageMagick 7 on 600×400 xc:none: an ellipse in 70 % black blurred 0x14, ' .
			'a red ramp from transparent, an arrow in a circle, opaque corners labelled in Arial Bold',
		'groups' => ['resize', 'crop', 'formats'],
	],
	'alpha.webp' => [
		'text'   => 'The same as lossy WebP with alpha.',
		'made'   => 'cwebp -q 80 -alpha_q 100 alpha.png',
		'groups' => ['resize', 'crop', 'formats'],
	],
	'still.gif' => [
		'text'   => 'A still GIF with one transparent color.',
		'made'   => 'magick alpha.png -resize 300x200 -channel A -threshold 50% +channel -colors 32',
		'groups' => ['resize', 'formats'],
	],
	'animated.gif' => [
		'text'   => 'Eight full frames: a ball runs from left to right.',
		'made'   => 'The card at 300×200 with a red ball from x 40 to 260 and the frame number, ' .
			'-delay 25 -loop 0 -colors 64 +dither',
		'groups' => ['frames'],
	],
	'optimized.gif' => [
		'text'   => 'Optimised by ImageMagick: frame 1 is only the clock, placed on a ' .
			'transparent screen; the hand turns once.',
		'made'   => 'Twelve 300×200 frames on xc:none, the hand turned by 30° each, ' .
			'-delay 20 -loop 0 -dispose background -layers optimize',
		'groups' => ['frames'],
	],
	'animated.webp' => [
		'text'   => 'The ball as animated WebP, later frames partial.',
		'made'   => 'gif2webp -lossy -q 70 animated.gif',
		'groups' => ['frames'],
	],
	'tiny.png' => [
		'text'   => '12×8 pixels with colored corners, smaller than every options: never enlarged.',
		'made'   => 'magick -size 12x8 xc:white, corner pixels in the edge colors, a black 2×2 center',
		'groups' => $card,
	],
];
