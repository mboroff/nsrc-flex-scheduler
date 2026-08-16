<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Schedule a Radio';
$myUserId  = $_SESSION['user_id'];
$isAdmin   = session_is_admin();

$date = $_GET['date'] ?? '';
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
$validDate = $dateObj && $dateObj->format('Y-m-d') === $date;

// Radios, in the display order requested, mapped to their DB id.
$radioOrder = ['MunAV640', 'MunEndfed', 'Northbrook', 'Northfield', 'Skokie'];

// Identity color per radio, matching the site's spectrum-sweep palette -
// gives each column a quick-glance color so the grid reads faster.
$radioColors = [
    'MunAV640'   => '#0891b2', // cyan
    'MunEndfed'  => '#7c3aed', // violet
    'Northbrook' => '#d97706', // amber
    'Northfield' => '#16a34a', // green
    'Skokie'     => '#e11d48', // rose
];

$db = get_db();
$radioRows = $db->query('SELECT id, name FROM radios')->fetchAll(PDO::FETCH_ASSOC);
$radiosByName = [];
foreach ($radioRows as $r) {
    $radiosByName[$r['name']] = (int) $r['id'];
}

// Handle a click (POST) before rendering, so the page reflects the result
// and a browser refresh doesn't resubmit the action (POST/redirect/GET).
if ($validDate && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $radioId = (int) ($_POST['radio_id'] ?? 0);
    $hour    = (int) ($_POST['hour'] ?? -1);
    $knownRadioId = in_array($radioId, $radiosByName, true);

    if ($knownRadioId && $hour >= 0 && $hour <= 23) {
        $slotStart = sprintf('%s %02d:00:00', $date, $hour);
        $slotEndHour = $hour + 1;
        if ($slotEndHour <= 23) {
            $slotEnd = sprintf('%s %02d:00:00', $date, $slotEndHour);
        } else {
            $nextDay = (clone $dateObj)->modify('+1 day')->format('Y-m-d');
            $slotEnd = sprintf('%s 00:00:00', $nextDay);
        }

        $existingStmt = $db->prepare(
            "SELECT reservations.id, reservations.user_id,
                    COALESCE(users.call_sign, reservations.call_sign_snapshot) AS call_sign
             FROM reservations
             LEFT JOIN users ON users.id = reservations.user_id
             WHERE reservations.radio_id = ? AND reservations.start_time = ?"
        );
        $existingStmt->execute([$radioId, $slotStart]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        $slotStartTime = DateTime::createFromFormat('Y-m-d H:i:s', $slotStart);
        $isPastSlot = $slotStartTime < new DateTime(date('Y-m-d H:00:00'));

        if ($isAdmin && $existing) {
            // Admin can blank out any filled slot - theirs, someone else's,
            // past or future - as a correction/cleanup tool.
            $delete = $db->prepare('DELETE FROM reservations WHERE id = ?');
            $delete->execute([$existing['id']]);
        } elseif ($isPastSlot) {
            // Slots that have already started can't be booked or changed
            // by a regular click - silently ignore it rather than letting
            // anyone rewrite what already happened.
        } elseif (!$existing) {
            // Slot is open - reserve it under the logged-in Call Sign.
            $insert = $db->prepare(
                'INSERT INTO reservations (radio_id, user_id, call_sign_snapshot, start_time, end_time, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$radioId, $myUserId, $_SESSION['call_sign'], $slotStart, $slotEnd, date('Y-m-d H:i:s')]);
        } elseif ((int) $existing['user_id'] === (int) $myUserId) {
            // It's my own reservation - clicking again releases it.
            $delete = $db->prepare('DELETE FROM reservations WHERE id = ?');
            $delete->execute([$existing['id']]);
        } else {
            // Reserved by someone else - leave it alone and explain why.
            $_SESSION['flash_error'] = 'That slot is already reserved by ' . $existing['call_sign'] . '.';
        }
    }

    header('Location: schedule_day.php?date=' . urlencode($date));
    exit;
}

$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

// Load this date's reservations into a quick lookup: [radio_id][hour] = ['call_sign'=>.., 'user_id'=>..]
$slotData = [];
if ($validDate) {
    $dayStart = $date . ' 00:00:00';
    $nextDay  = (clone $dateObj)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $stmt = $db->prepare(
        "SELECT reservations.radio_id, reservations.start_time, reservations.user_id,
                COALESCE(users.call_sign, reservations.call_sign_snapshot) AS call_sign
         FROM reservations
         LEFT JOIN users ON users.id = reservations.user_id
         WHERE reservations.start_time >= ? AND reservations.start_time < ?"
    );
    $stmt->execute([$dayStart, $nextDay]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hour = (int) substr($row['start_time'], 11, 2);
        $slotData[(int) $row['radio_id']][$hour] = [
            'call_sign' => $row['call_sign'],
            'user_id'   => (int) $row['user_id'],
        ];
    }
}

function twelve_hour_label(int $hour): string {
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }
    $ampm = $hour < 12 ? 'AM' : 'PM';
    return sprintf('%d:00 %s', $displayHour, $ampm);
}

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:900px;">
    <?php if (!$validDate): ?>
        <h2>Schedule a Radio</h2>
        <div class="message error">No valid date was given.</div>
        <div class="actions-row">
            <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
        </div>
    <?php else: ?>
        <h2><?php echo h($dateObj->format('l, F j, Y')); ?></h2>
        <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
            Click an open slot to reserve it under your Call Sign, or click your
            own reservation again to release it. Past time slots are locked.
            <?php if ($isAdmin): ?>
                As admin, you can also click any filled slot to clear it.
            <?php endif; ?>
        </p>

        <?php if ($flashError): ?>
            <div class="message error"><?php echo h($flashError); ?></div>
        <?php endif; ?>

        <table class="schedule-grid">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php foreach ($radioOrder as $radioName): ?>
                        <th style="--radio-color: <?php echo h($radioColors[$radioName] ?? '#0891b2'); ?>;">
                            <?php echo h($radioName); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php for ($hour = 0; $hour <= 23; $hour++): ?>
                    <tr>
                        <td class="hour-label"><?php echo h(twelve_hour_label($hour)); ?></td>
                        <?php foreach ($radioOrder as $radioName): ?>
                            <?php
                                $radioId = $radiosByName[$radioName] ?? null;
                                $slot = ($radioId !== null) ? ($slotData[$radioId][$hour] ?? null) : null;
                                $isMine = $slot && $slot['user_id'] === (int) $myUserId;
                                $cellClass = $slot ? ($isMine ? 'occupied-self' : 'occupied-other') : 'open-slot';

                                $cellDateTime = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%s %02d:00:00', $date, $hour));
                                $cellIsPast = $cellDateTime < new DateTime(date('Y-m-d H:00:00'));
                                // A regular user can't act on a past slot at all. Admin can
                                // still click a past slot that's filled, to blank it out.
                                $disableCell = $cellIsPast && !($isAdmin && $slot);
                                if ($cellIsPast) {
                                    $cellClass .= ' past-slot';
                                }
                            ?>
                            <td class="<?php echo $cellClass; ?>">
                                <?php if ($radioId === null): ?>
                                    &mdash;
                                <?php else: ?>
                                    <form method="post" action="schedule_day.php?date=<?php echo h($date); ?>">
                                        <input type="hidden" name="radio_id" value="<?php echo (int) $radioId; ?>">
                                        <input type="hidden" name="hour" value="<?php echo $hour; ?>">
                                        <button type="submit" class="slot-btn" <?php echo $disableCell ? 'disabled' : ''; ?>>
                                            <?php echo $slot ? h($slot['call_sign']) : ''; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="actions-row">
            <a class="btn btn-secondary"
               href="schedule.php?year=<?php echo (int) $dateObj->format('Y'); ?>&month=<?php echo (int) $dateObj->format('n'); ?>">
                Back to Calendar
            </a>
            <a class="btn btn-secondary" href="schedule_day.php?date=<?php echo h(date('Y-m-d')); ?>">Today</a>
            <a class="btn btn-secondary" href="my_reservations.php">My Reservations</a>
            <a class="btn btn-secondary" href="all_reservations.php">All Reservations</a>
            <a class="btn btn-secondary" href="logout.php">Log Out</a>
        </div>
    <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
