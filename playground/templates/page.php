<?php
/**
 * @var array $page
 */
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Darkroom playground</title>
	<link rel="stylesheet" href="/style.css">
</head>
<body>
	<header>
		<h1>Darkroom playground</h1>
		<p>
			<span>code <code><?= $page['code'] ?></code></span>
			<a href="<?= htmlspecialchars($page['refresh']) ?>">Regenerate</a>
		</p>
	</header>

	<table class="drivers">
		<thead>
			<tr>
				<th>Driver</th>
				<th>Library</th>
				<th>Reads</th>
				<th>Writes</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($page['drivers'] as $driver): ?>
			<tr>
				<th><?= $driver['name'] ?></th>
				<td><?= htmlspecialchars($driver['library']) ?></td>
				<td><?= $driver['read'] ?></td>
				<td><?= $driver['write'] ?></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>

	<nav>
		<?php foreach ($page['filters'] as $label => $links): ?>
		<p>
			<span><?= $label ?></span>
			<?php foreach ($links as $link): ?>
			<a
				href="<?= htmlspecialchars($link['url']) ?>"
				aria-current="<?= $link['current'] ?>"
			>
				<?= $link['text'] ?>
			</a>
			<?php endforeach ?>
		</p>
		<?php endforeach ?>
		<p>
			<span>checks</span>
			<?php foreach ($page['checks'] as $count): ?>
			<b data-state="<?= $count['state'] ?>"><?= $count['text'] ?></b>
			<?php endforeach ?>
		</p>
	</nav>

	<main>
		<?php foreach ($page['sources'] as $source): ?>
		<section>
			<header>
				<a
					href="<?= $source['url'] ?>"
					target="_blank"
					class="thumb"
				>
					<img src="<?= $source['url'] ?>" alt="">
				</a>
				<h2>
					<a href="<?= htmlspecialchars($source['link']) ?>">
						<?= $source['name'] ?>
					</a>
				</h2>
				<p><?= htmlspecialchars($source['text']) ?></p>
				<p class="facts"><?= $source['facts'] ?></p>
			</header>
			<table class="cells">
				<thead>
					<tr>
						<th>Case</th>
						<?php foreach ($page['columns'] as $driver): ?>
						<th><?= $driver ?></th>
						<?php endforeach ?>
					</tr>
				</thead>
				<?php foreach ($source['groups'] as $group => $rows): ?>
				<tbody>
					<tr>
						<th colspan="<?= $page['span'] ?>"><?= $group ?></th>
					</tr>
					<?php foreach ($rows as $row): ?>
					<?php require __DIR__ . '/row.php' ?>
					<?php endforeach ?>
				</tbody>
				<?php endforeach ?>
			</table>
		</section>
		<?php endforeach ?>
	</main>
</body>
</html>
