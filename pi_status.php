<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// This page can reboot or power off the host, so restrict it to the
// hardcoded super-admin rather than every delegated administrator.
if (strtoupper($_SESSION['call_sign'] ?? '') !== ADMIN_CALL_SIGN) {
    header('Location: schedule.php');
    exit;
}

$pageTitle = 'Pi Status';
$actionMessage = '';
$actionError = '';

// Prevent a different site from submitting a reboot/shutdown form using an
// administrator's active session.
if (empty($_SESSION['pi_status_csrf'])) {
    $_SESSION['pi_status_csrf'] = bin2hex(random_bytes(32));
}

/**
 * Executes a fixed, root-owned helper via passwordless sudo.
 *
 * No request value is ever placed in a shell command.  sudo -n prevents a
 * web request from hanging while sudo waits for a password.
 *
 * @return array{0: bool, 1: string}
 */
function run_pi_power_action(string $action): array {
    // Fixed, hardcoded argument lists - nothing from the request is ever
    // placed into these. Matches the sudoers grant in README.md
    // ("www-data ALL=(root) NOPASSWD: /sbin/reboot, /sbin/shutdown -h").
    $helpers = [
        'reboot'   => ['/sbin/reboot'],
        'shutdown' => ['/sbin/shutdown', '-h'],
    ];

    if (!isset($helpers[$action])) {
        return [false, 'Invalid Pi action.'];
    }

    $cmd = '/usr/bin/sudo -n -- ' . implode(' ', array_map('escapeshellarg', $helpers[$action]));

    $output = [];
    $exitCode = 1;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        error_log(sprintf('Pi %s helper failed (exit %d): %s', $action, $exitCode, implode("\n", $output)));
        return [false, 'The Pi command was not accepted. Check the web-control setup and Apache error log.'];
    }

    return [true, ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!is_string($csrf) || !hash_equals($_SESSION['pi_status_csrf'], $csrf)) {
        http_response_code(403);
        $actionError = 'The request could not be verified. Reload the page and try again.';
    } elseif ($action === 'reboot' || $action === 'shutdown') {
        [$ok, $error] = run_pi_power_action($action);
        if ($ok) {
            $actionMessage = $action === 'reboot'
                ? 'Reboot request accepted. The Pi will restart shortly; this page and the site will be briefly unreachable.'
                : 'Shutdown request accepted. The Pi will power off in about one minute; the site will be unreachable until it is turned back on.';
        } else {
            $actionError = $error;
        }
    } else {
        http_response_code(400);
        $actionError = 'Invalid Pi action.';
    }
}

/** Return a read-only command's trimmed output, or null on failure. */
function run_command(string $cmd): ?string {
    $output = @shell_exec($cmd . ' 2>/dev/null');
    return $output === null ? null : trim($output);
}

// Read the kernel thermal-zone value directly.  This avoids depending on
// vcgencmd being in Apache's PATH or executable by the www-data account.
$tempF = null;
$tempRaw = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
if ($tempRaw !== false && is_numeric(trim($tempRaw))) {
    $tempC = (float) trim($tempRaw) / 1000;
    $tempF = $tempC * 9 / 5 + 32;
}

$cpuPercent = null;
$cpuRaw = run_command("top -d 0.5 -b -n2 | awk '/Cpu\\(s\\)/ { value = \$2 + \$4 } END { print value }'");
if ($cpuRaw !== null && is_numeric($cpuRaw)) {
    $cpuPercent = (float) $cpuRaw;
}

$memPercent = null;
$memRaw = run_command("free | awk '/Mem:/ {print (\$3 / \$2) * 100.0}'");
if ($memRaw !== null && is_numeric($memRaw)) {
    $memPercent = (float) $memRaw;
}

$diskPercent = null;
$diskRaw = run_command("df -P / | awk 'END {gsub(/%/, \"\", \$5); print \$5}'");
if ($diskRaw !== null && is_numeric($diskRaw)) {
    $diskPercent = (float) $diskRaw;
}

function gauge_color(float $percent): string {
    if ($percent >= 90) return '#dc2626';
    if ($percent >= 70) return '#d97706';
    return '#16a34a';
}

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:640px;">
    <h2>Pi Status</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">Live system status for the Raspberry Pi running this site.</p>

    <?php if ($actionMessage): ?><div class="message success"><?php echo h($actionMessage); ?></div><?php endif; ?>
    <?php if ($actionError): ?><div class="message error"><?php echo h($actionError); ?></div><?php endif; ?>

    <div class="pi-gauges">
        <?php
        $gauges = [
            ['label' => 'Temperature', 'value' => $tempF, 'unit' => '&deg;F', 'max' => 200],
            ['label' => 'CPU Load', 'value' => $cpuPercent, 'unit' => '%', 'max' => 100],
            ['label' => 'Memory Usage', 'value' => $memPercent, 'unit' => '%', 'max' => 100],
            ['label' => 'Disk Usage', 'value' => $diskPercent, 'unit' => '%', 'max' => 100],
        ];
        foreach ($gauges as $g):
            $value = $g['value'];
            $percentOfMax = $value !== null ? min(100, ($value / $g['max']) * 100) : 0;
            $color = $value !== null ? gauge_color($percentOfMax) : '#a3adba';
            $display = $value !== null ? number_format($value, 1) . $g['unit'] : 'N/A';
        ?>
            <div class="pi-gauge">
                <div class="pi-gauge-ring" style="background: conic-gradient(<?php echo $color; ?> <?php echo $percentOfMax; ?>%, #e2e8f0 0);">
                    <div class="pi-gauge-inner"><?php echo html_entity_decode($display); ?></div>
                </div>
                <div class="pi-gauge-label"><?php echo h($g['label']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="actions-row" style="margin-top:24px;">
        <a class="btn btn-secondary" href="pi_status.php">Refresh</a>
        <button type="button" class="btn btn-secondary" onclick="confirmPiAction('reboot', 'Reboot the Pi now?')">Reboot</button>
        <button type="button" class="btn btn-danger" onclick="confirmPiAction('shutdown', 'Shut down the Pi now?')">Shutdown</button>
    </div>
    <div class="actions-row"><a class="btn btn-secondary" href="schedule.php">Back to Calendar</a></div>

    <div id="confirm-overlay" class="confirm-overlay">
        <div class="confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="confirm-text">
            <p id="confirm-text">Are you sure?</p>
            <div class="confirm-buttons">
                <button type="button" class="btn btn-danger" onclick="confirmModalAnswer(true)">Y</button>
                <button type="button" class="btn btn-secondary" onclick="confirmModalAnswer(false)">N</button>
            </div>
        </div>
    </div>

    <form id="pi-action-form" method="post" action="pi_status.php" style="display:none;">
        <input type="hidden" name="action" id="pi-action-field" value="">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['pi_status_csrf']); ?>">
    </form>

    <script>
        var pendingPiAction = null;
        function confirmPiAction(action, question) {
            pendingPiAction = action;
            document.getElementById('confirm-text').textContent = question + ' Y/N';
            document.getElementById('confirm-overlay').classList.add('is-visible');
        }
        function confirmModalAnswer(yes) {
            document.getElementById('confirm-overlay').classList.remove('is-visible');
            if (yes && pendingPiAction) {
                document.getElementById('pi-action-field').value = pendingPiAction;
                document.getElementById('pi-action-form').submit();
            }
            pendingPiAction = null;
        }
    </script>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>