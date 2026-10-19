<?php

use Darkroom\Format;
use Darkroom\Playground\Filter;
use Darkroom\Playground\Playground;
use Darkroom\Playground\View;

require __DIR__ . '/../vendor/autoload.php';

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/style.css') {
	return false;
}

// sources and thumbs, typed by their bytes
if (preg_match('#^/(sources|cache)/#', $path, $match) === 1) {
	$dir  = realpath(__DIR__ . '/' . $match[1]);
	$file = realpath(__DIR__ . $path);

	if (
		$dir === false ||
		$file === false ||
		str_starts_with($file, $dir . '/') === false
	) {
		http_response_code(404);
		return true;
	}

	$bytes  = (string)file_get_contents($file, false, null, 0, 64);
	$format = Format::detect($bytes);

	header('Content-Type: ' . ($format?->mime() ?? 'application/octet-stream'));
	header('Cache-Control: no-cache');
	readfile($file);

	return true;
}

if ($path !== '/') {
	http_response_code(404);
	echo 'Not found';
	return true;
}

// making every row takes longer than the server's default limit
set_time_limit(0);

$playground = new Playground(__DIR__);
$filter     = Filter::from($_GET);
$view       = new View($playground, $filter);

if (isset($_GET['refresh']) === true) {
	$playground->cache->clear();

	header('Location: ' . $filter->link($path), true, 303);
	return true;
}

try {
	$page = $view->page();

	require __DIR__ . '/templates/page.php';
} catch (Throwable $exception) {
	http_response_code(500);
	header('Content-Type: text/plain');
	echo $exception;
}

return true;
