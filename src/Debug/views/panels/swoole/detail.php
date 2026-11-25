<?php

use yii\helpers\Html;

/** @var \Dacheng\Yii2\Swoole\Debug\Panels\SwoolePanel $panel */

$data = is_array($panel->data) ? $panel->data : [];
$req = $data['request'] ?? [];
$runtime = $data['runtime'] ?? [];
$worker = $runtime['worker'] ?? [];
$config = $runtime['config'] ?? [];
$requests = $runtime['requests'] ?? [];
$coStats = $runtime['coroutine'] ?? [];
$serverStats = $runtime['server'] ?? [];
$pools = $runtime['pools'] ?? [];
$appInfo = $data['app'] ?? [];
$sessionInfo = $data['session'] ?? null;
$logWorkers = $data['log_workers'] ?? [];
$alerts = $data['alerts'] ?? [];

$poolSummary = $req['pool_summary'] ?? [];
$poolEvents = $req['pool_events'] ?? [];
$concurrentSummary = $req['concurrent_summary'] ?? [];
$concurrentBatches = $req['concurrent_batches'] ?? [];
$redisSummary = $req['redis_summary'] ?? [];
$redisCommands = $req['redis_commands'] ?? [];

$formatBytes = static function (int|float $bytes): string {
    $sign = $bytes < 0 ? '-' : '';
    $abs = abs((float) $bytes);
    if ($abs >= 1048576) {
        return $sign . sprintf('%.3f MB', $abs / 1048576);
    }
    if ($abs >= 1024) {
        return $sign . sprintf('%.2f KB', $abs / 1024);
    }

    return $sign . (int) $abs . ' B';
};

$renderKvTable = static function (string $caption, array $rows): string {
    $html = '<h3>' . Html::encode($caption) . '</h3>';
    $html .= '<div class="table-responsive"><table class="table table-condensed table-bordered table-striped table-hover">';
    $html .= '<thead><tr><th style="width: 30%;">Metric</th><th>Value</th></tr></thead><tbody>';
    foreach ($rows as $k => $v) {
        if (is_bool($v)) {
            $display = $v ? 'Yes' : 'No';
        } elseif ($v === null || $v === '') {
            $display = '—';
        } elseif (is_array($v)) {
            $display = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $display = (string) $v;
        }
        $html .= '<tr><th>' . Html::encode((string) $k) . '</th><td><code>' . Html::encode($display) . '</code></td></tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
};
?>
<h1>Swoole Runtime &amp; Coroutine Telemetry</h1>

<?php foreach ($alerts as $alert): ?>
    <div class="alert alert-<?= ($alert['level'] ?? '') === 'error' ? 'danger' : 'warning' ?>">
        <strong><?= strtoupper(Html::encode((string) ($alert['level'] ?? 'warning'))) ?>:</strong>
        <?= Html::encode((string) ($alert['message'] ?? '')) ?>
    </div>
<?php endforeach; ?>

<ul class="nav nav-tabs">
    <li class="nav-item">
        <a class="nav-link active" data-toggle="tab" href="#swoole-tab-request" role="tab">Coroutine &amp; Request</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#swoole-tab-pools" role="tab">
            Connection Pools (<?= count($pools) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#swoole-tab-io" role="tab">
            Redis / Session / Async Log (<?= (int) ($redisSummary['count'] ?? 0) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#swoole-tab-runtime" role="tab">Worker &amp; Server</a>
    </li>
</ul>

<div class="tab-content" style="margin-top: 15px;">
    <div class="tab-pane fade active show" id="swoole-tab-request">
        <?= $renderKvTable('Request Coroutine Context', [
            'Request ID' => $req['request_id'] ?? null,
            'Coroutine ID (CID)' => $req['cid'] ?? null,
            'Parent CID' => $req['parent_cid'] ?? null,
            'Request Duration' => isset($req['duration_ms']) ? sprintf('%.3f ms', (float) $req['duration_ms']) : null,
            'Pool Wait Time' => sprintf('%.3f ms', (float) ($poolSummary['total_wait_ms'] ?? 0.0)),
            'Coroutine Switches (Delta)' => $req['switch_delta'] ?? null,
            'Memory Start' => isset($req['memory_start']) ? $formatBytes((int) $req['memory_start']) : null,
            'Memory End' => isset($req['memory_end']) ? $formatBytes((int) $req['memory_end']) : null,
            'Memory Delta' => isset($req['memory_delta']) ? ($req['memory_delta'] >= 0 ? '+' : '') . $formatBytes((int) $req['memory_delta']) : null,
            'Worker Memory (Current / Peak)' => sprintf('%.2f MB / %.2f MB', (float) ($worker['memory_mb'] ?? 0), (float) ($worker['memory_peak_mb'] ?? 0)),
            'Shared Component IDs' => $appInfo['shared_component_count'] ?? 0,
            'DI Coroutine Singletons' => $appInfo['di_coroutine_singletons'] ?? 0,
        ]) ?>

        <?php if (!empty($concurrentBatches)): ?>
            <h3>Structured Concurrency Batches (<?= (int) ($concurrentSummary['batches'] ?? 0) ?> batches, <?= (int) ($concurrentSummary['total_tasks'] ?? 0) ?> tasks, max concurrency <?= (int) ($concurrentSummary['max_concurrency'] ?? 0) ?>)</h3>
            <div class="table-responsive">
                <table class="table table-condensed table-bordered table-striped table-hover">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Caller CID</th>
                        <th>Tasks</th>
                        <th>Concurrency Limit</th>
                        <th>Wall Duration (ms)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($concurrentBatches as $idx => $batch): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><code><?= Html::encode((string) ($batch['cid'] ?? '-')) ?></code></td>
                            <td><?= (int) ($batch['tasks'] ?? 0) ?></td>
                            <td><?= (int) ($batch['concurrency_limit'] ?? 0) === 0 ? 'unlimited' : (int) $batch['concurrency_limit'] ?></td>
                            <td><?= sprintf('%.3f ms', (float) ($batch['duration_ms'] ?? 0.0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3>Request-Scoped Components Instantiated (<?= count($appInfo['scoped_components'] ?? []) ?>)</h3>
        <div class="table-responsive">
            <table class="table table-condensed table-bordered table-striped table-hover">
                <thead>
                <tr>
                    <th>Component ID</th>
                    <th>Class</th>
                    <th>ResettableInterface</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($appInfo['scoped_components'])): ?>
                    <tr><td colspan="3">None recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($appInfo['scoped_components'] as $comp): ?>
                        <tr>
                            <td><code><?= Html::encode((string) $comp['id']) ?></code></td>
                            <td><code><?= Html::encode((string) $comp['class']) ?></code></td>
                            <td><?= !empty($comp['resettable']) ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="swoole-tab-pools">
        <h3>Worker Pool Watermarks</h3>
        <div class="table-responsive">
            <table class="table table-condensed table-bordered table-striped table-hover">
                <thead>
                <tr>
                    <th>Pool</th>
                    <th>Capacity</th>
                    <th>Min Idle</th>
                    <th>Created</th>
                    <th>Idle</th>
                    <th>In Use</th>
                    <th>Waiters</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($pools)): ?>
                    <tr><td colspan="8">No connection pools initialized in this worker yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($pools as $key => $stats): ?>
                        <tr>
                            <td><code><?= Html::encode((string) ($stats['name'] ?? $key)) ?></code></td>
                            <td><?= (int) ($stats['capacity'] ?? 0) ?></td>
                            <td><?= (int) ($stats['min_idle'] ?? 0) ?></td>
                            <td><?= (int) ($stats['created'] ?? 0) ?></td>
                            <td><?= (int) ($stats['idle'] ?? 0) ?></td>
                            <td><?= (int) ($stats['in_use'] ?? 0) ?></td>
                            <td><?= (int) ($stats['waiters'] ?? 0) ?></td>
                            <td><?= !empty($stats['closed']) ? '<span class="badge badge-danger">Closed</span>' : '<span class="badge badge-success">Open</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?= $renderKvTable('Per-Request Pool Summary', [
            'Acquires' => $poolSummary['acquires'] ?? 0,
            'Releases' => $poolSummary['releases'] ?? 0,
            'Discards (Broken Connections)' => $poolSummary['discards'] ?? 0,
            'Newly Created Connections' => $poolSummary['created'] ?? 0,
            'Idle Health Pings' => $poolSummary['pinged'] ?? 0,
            'Exhausted Timeouts' => $poolSummary['exhausted'] ?? 0,
            'Total Wait Time' => sprintf('%.3f ms', (float) ($poolSummary['total_wait_ms'] ?? 0.0)),
            'Truncated Events' => $req['pool_events_truncated'] ?? 0,
        ]) ?>

        <?php if (!empty($poolEvents)): ?>
            <h3>Per-Request Pool Event Trace (<?= count($poolEvents) ?>)</h3>
            <div class="table-responsive">
                <table class="table table-condensed table-bordered table-striped table-hover">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Action</th>
                        <th>Pool</th>
                        <th>CID</th>
                        <th>Wait (ms)</th>
                        <th>Flags</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($poolEvents as $idx => $evt): ?>
                        <?php
                        $flags = [];
                        if (!empty($evt['created'])) {
                            $flags[] = 'created';
                        }
                        if (!empty($evt['pinged'])) {
                            $flags[] = 'pinged';
                        }
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><code><?= Html::encode((string) ($evt['action'] ?? '')) ?></code></td>
                            <td><code><?= Html::encode((string) ($evt['pool'] ?? '')) ?></code></td>
                            <td><?= (int) ($evt['cid'] ?? 0) ?></td>
                            <td><?= sprintf('%.3f', (float) ($evt['wait_ms'] ?? 0.0)) ?></td>
                            <td><?= $flags === [] ? '—' : Html::encode(implode(', ', $flags)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="swoole-tab-io">
        <?= $renderKvTable('Redis Summary', [
            'Commands Executed' => $redisSummary['count'] ?? 0,
            'Total Duration' => sprintf('%.3f ms', (float) ($redisSummary['total_duration_ms'] ?? 0.0)),
            'Sticky Commands (MULTI/WATCH/etc.)' => $redisSummary['sticky_commands'] ?? 0,
            'Socket Retries' => $redisSummary['retries'] ?? 0,
            'Failed Commands' => $redisSummary['failed'] ?? 0,
            'Truncated Records' => $req['redis_commands_truncated'] ?? 0,
        ]) ?>

        <?php if (!empty($redisCommands)): ?>
            <h3>Redis Command Trace (<?= count($redisCommands) ?>)</h3>
            <div class="table-responsive">
                <table class="table table-condensed table-bordered table-striped table-hover">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Command</th>
                        <th>CID</th>
                        <th>Duration (ms)</th>
                        <th>Sticky</th>
                        <th>Retries</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($redisCommands as $idx => $cmd): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><code><?= Html::encode((string) ($cmd['command'] ?? '')) ?></code></td>
                            <td><?= (int) ($cmd['cid'] ?? 0) ?></td>
                            <td><?= sprintf('%.3f', (float) ($cmd['duration_ms'] ?? 0.0)) ?></td>
                            <td><?= !empty($cmd['sticky']) ? 'Yes' : 'No' ?></td>
                            <td><?= (int) ($cmd['retries'] ?? 0) ?></td>
                            <td><?= !empty($cmd['failed']) ? '<span class="badge badge-danger">Failed</span>' : '<span class="badge badge-success">OK</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (is_array($sessionInfo)): ?>
            <?= $renderKvTable('Redis Session Activity', [
                'Persistence Mode' => $sessionInfo['mode'] ?? null,
                'Session ID (Masked)' => $sessionInfo['session_id_masked'] ?? null,
                'Dirty' => $sessionInfo['dirty'] ?? false,
                'Key Count' => $sessionInfo['key_count'] ?? 0,
            ]) ?>
        <?php endif; ?>

        <h3>Async Log Workers (<?= count($logWorkers) ?>)</h3>
        <div class="table-responsive">
            <table class="table table-condensed table-bordered table-striped table-hover">
                <thead>
                <tr>
                    <th>Target</th>
                    <th>Log File</th>
                    <th>Buffered</th>
                    <th>Max Buffer</th>
                    <th>Dropped</th>
                    <th>Running</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($logWorkers)): ?>
                    <tr><td colspan="6">No FileTarget async log workers configured.</td></tr>
                <?php else: ?>
                    <?php foreach ($logWorkers as $lw): ?>
                        <tr>
                            <td><code><?= Html::encode((string) $lw['target']) ?></code></td>
                            <td><code><?= Html::encode((string) $lw['log_file']) ?></code></td>
                            <td><?= (int) $lw['buffered'] ?></td>
                            <td><?= (int) $lw['max_buffer_size'] ?></td>
                            <td><?= (int) $lw['dropped'] ?></td>
                            <td><?= !empty($lw['running']) ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="swoole-tab-runtime">
        <?= $renderKvTable('Worker & Engine Overview', [
            'PHP Version' => $runtime['php_version'] ?? PHP_VERSION,
            'Swoole Version' => $runtime['swoole_version'] ?? null,
            'Server Mode' => $config['mode'] ?? null,
            'Workers Configured' => $config['workers'] ?? null,
            'Hook Flags' => isset($config['hook_flags']) ? sprintf('0x%X (%d)', (int) $config['hook_flags'], (int) $config['hook_flags']) : null,
            'Max Coroutine' => $config['max_coroutine'] ?? null,
            'Max Memory Threshold' => isset($config['max_memory_mb']) ? ((int) $config['max_memory_mb'] > 0 ? $config['max_memory_mb'] . ' MB' : 'Disabled') : null,
            'Watch Mode' => $config['watch'] ?? null,
            'Worker ID' => $worker['id'] ?? null,
            'Worker PID' => $worker['pid'] ?? null,
            'Worker Uptime' => isset($worker['uptime_seconds']) ? $worker['uptime_seconds'] . ' s' : null,
            'Worker Total Requests' => $requests['total_requests'] ?? 0,
            'Worker Active Requests' => $requests['active_requests'] ?? 0,
        ]) ?>

        <?php if (!empty($coStats)): ?>
            <?= $renderKvTable('Coroutine::stats()', $coStats) ?>
        <?php endif; ?>

        <?php if (!empty($serverStats)): ?>
            <?= $renderKvTable('Server::stats()', $serverStats) ?>
        <?php endif; ?>
    </div>
</div>
