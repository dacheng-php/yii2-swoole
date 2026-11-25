<?php

use yii\helpers\Html;

/** @var \yii2swoole\debug\panels\SwoolePanel $panel */

$data = is_array($panel->data) ? $panel->data : [];
$req = $data['request'] ?? [];
$runtime = $data['runtime'] ?? [];
$worker = $runtime['worker'] ?? [];
$pools = $runtime['pools'] ?? [];
$alerts = $data['alerts'] ?? [];

$workerId = $worker['id'] ?? null;
$cid = $req['cid'] ?? null;
$memDeltaBytes = (int) ($req['memory_delta'] ?? 0);
$memDeltaMb = $memDeltaBytes / 1048576;
$memDeltaLabel = ($memDeltaMb >= 0 ? '+' : '') . sprintf('%.2f MB', $memDeltaMb);

$totalInUse = 0;
$totalCapacity = 0;
foreach ($pools as $poolStats) {
    $totalInUse += (int) ($poolStats['in_use'] ?? 0);
    $totalCapacity += (int) ($poolStats['capacity'] ?? 0);
}

$labelClass = 'yii-debug-toolbar__label_info';
foreach ($alerts as $alert) {
    if (($alert['level'] ?? '') === 'error') {
        $labelClass = 'yii-debug-toolbar__label_error';
        break;
    }
    if (($alert['level'] ?? '') === 'warning') {
        $labelClass = 'yii-debug-toolbar__label_warning';
    }
}

$titleParts = [];
if ($workerId !== null) {
    $titleParts[] = 'Worker #' . $workerId . ' (PID ' . ($worker['pid'] ?? '-') . ')';
}
if ($cid !== null) {
    $titleParts[] = 'CID ' . $cid;
}
if ($totalCapacity > 0) {
    $titleParts[] = "Pools {$totalInUse}/{$totalCapacity}";
}
$titleParts[] = 'Memory Delta ' . $memDeltaLabel;
foreach ($alerts as $alert) {
    $titleParts[] = strtoupper((string) ($alert['level'] ?? 'WARN')) . ': ' . (string) ($alert['message'] ?? '');
}
?>
<div class="yii-debug-toolbar__block">
    <a href="<?= Html::encode($panel->getUrl()) ?>" title="<?= Html::encode(implode(' | ', $titleParts)) ?>">
        Swoole
        <span class="yii-debug-toolbar__label <?= $labelClass ?>">
            <?= $workerId !== null ? 'W#' . Html::encode((string) $workerId) : 'CLI' ?><?= $cid !== null ? ' · CID ' . Html::encode((string) $cid) : '' ?>
        </span>
        <?php if ($totalCapacity > 0): ?>
            <span class="yii-debug-toolbar__label">Pool <?= $totalInUse ?>/<?= $totalCapacity ?></span>
        <?php endif; ?>
        <span class="yii-debug-toolbar__label"><?= Html::encode($memDeltaLabel) ?></span>
    </a>
</div>
