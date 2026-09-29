<?php
/**
 * Horizontal bar chart, drawn with plain markup.
 *
 * No chart library: these are counts with labels, and a list of bars
 * reads more accurately than a pie and stays legible when printed or
 * read by a screen reader.
 *
 * @var array  $chart_rows  each: label, open_count, blocked, overdue, color (optional), href (optional), avatar (optional)
 * @var string $chart_empty message when there is nothing to show
 *
 * Set 'avatar' => true on a row when the label is a person's name, and
 * the colour dot is replaced by their initials.
 */
$rows = $chart_rows ?? [];
$max  = 0;
foreach ($rows as $r) {
    $max = max($max, (int) $r['open_count']);
}
?>
<?php if (!$rows): ?>
  <p class="muted"><?= e($chart_empty ?? 'Nothing open right now.') ?></p>
<?php else: ?>
  <ul class="chart">
    <?php foreach ($rows as $r):
        $n       = (int) $r['open_count'];
        $blocked = (int) ($r['blocked'] ?? 0);
        $overdue = (int) ($r['overdue'] ?? 0);
        $pct     = $max > 0 ? max(2, (int) round($n * 100 / $max)) : 0;
        $colour  = !empty($r['color']) ? $r['color'] : null;
    ?>
      <li class="chart-row">
        <span class="chart-label" title="<?= e($r['label']) ?>">
          <?php if (!empty($r['avatar'])): ?>
            <span class="avatar avatar-sm av-<?= $r['label'] === 'Unassigned' ? 0 : avatar_tint($r['label']) ?>"
                  aria-hidden="true"><?= e($r['label'] === 'Unassigned' ? '?' : initials($r['label'])) ?></span>
          <?php elseif ($colour): ?>
            <span class="chart-dot" style="background: <?= e($colour) ?>"></span>
          <?php endif; ?>
          <?php if (!empty($r['href'])): ?>
            <a href="<?= e($r['href']) ?>"><?= e($r['label']) ?></a>
          <?php else: ?>
            <?= e($r['label']) ?>
          <?php endif; ?>
        </span>

        <span class="chart-track">
          <span class="chart-fill"
                style="width: <?= $pct ?>%<?= $colour ? '; background: ' . e($colour) : '' ?>"></span>
        </span>

        <span class="chart-value">
          <?= $n ?>
          <?php if ($blocked > 0): ?><span class="pill pill-alert" title="Blocked"><?= $blocked ?></span><?php endif; ?>
          <?php if ($overdue > 0): ?><span class="pill pill-warn" title="Overdue"><?= $overdue ?></span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php unset($chart_rows, $chart_empty); ?>
