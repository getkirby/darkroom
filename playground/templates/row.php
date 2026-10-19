<?php
/**
 * @var array $row
 */
?>
<tr>
	<th>
		<?php foreach ($row['options'] as $option): ?>
		<code><?= htmlspecialchars($option) ?></code>
		<?php endforeach ?>
		<span class="facts"><?= $row['plan'] ?></span>
	</th>
	<?php foreach ($row['cells'] as $cell): ?>
	<td data-state="<?= $cell['state'] ?>">
		<?php if ($cell['url'] !== null): ?>
		<a
			href="<?= $cell['url'] ?>"
			target="_blank"
			class="thumb"
		>
			<img
				src="<?= $cell['url'] ?>"
				alt=""
				loading="lazy"
				onerror="this.parentNode.classList.add('broken')"
			>
		</a>
		<p class="facts">
			<?= $cell['facts'] ?>
			<?php if ($cell['delta'] !== null): ?>
			·
			<span
				class="delta"
				data-level="<?= $cell['level'] ?>"
				title="<?= $cell['title'] ?>"
			>
				<?= $cell['delta'] ?>
			</span>
			<?php endif ?>
		</p>
		<?php endif ?>
		<?php foreach ($cell['issues'] as $issue): ?>
		<p class="issue"><?= htmlspecialchars($issue) ?></p>
		<?php endforeach ?>
		<?php foreach ($cell['notes'] as $note): ?>
		<p class="note"><?= htmlspecialchars($note) ?></p>
		<?php endforeach ?>
	</td>
	<?php endforeach ?>
</tr>
