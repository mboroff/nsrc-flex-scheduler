<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Scheduling';

// Real "today", used only to decide which cell gets the .today highlight -
// separate from whatever month/year the person is currently viewing.
$realToday    = new DateTime();
$realYear     = (int) $realToday->format('Y');
$realMonth    = (int) $realToday->format('n');
$realDay      = (int) $realToday->format('j');

// Month/year being viewed - defaults to the current month, but can be
// paged forward/back via ?year=&month= from the wedge links below.
$year  = isset($_GET['year'])  ? (int) $_GET['year']  : $realYear;
$month = isset($_GET['month']) ? (int) $_GET['month'] : $realMonth;

// Normalize out-of-range month values (e.g. month=13 or month=0) so paging
// across a year boundary works correctly.
$firstOfMonth = new DateTime();
$firstOfMonth->setDate($year, $month, 1);
$year  = (int) $firstOfMonth->format('Y');
$month = (int) $firstOfMonth->format('n');

$daysInMonth   = (int) $firstOfMonth->format('t');
$startWeekday  = (int) $firstOfMonth->format('w'); // 0 = Sunday ... 6 = Saturday
$monthYearName = $firstOfMonth->format('F Y');

// Previous / next month links.
$prev = (clone $firstOfMonth)->modify('-1 month');
$next = (clone $firstOfMonth)->modify('+1 month');
$prevUrl = 'schedule.php?year=' . $prev->format('Y') . '&month=' . $prev->format('n');
$nextUrl = 'schedule.php?year=' . $next->format('Y') . '&month=' . $next->format('n');

$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Build the calendar grid: array of weeks, each week is an array of 7 cells
// (day number, or null for blank leading/trailing cells).
$weeks = [];
$week  = array_fill(0, $startWeekday, null);
for ($day = 1; $day <= $daysInMonth; $day++) {
    $week[] = $day;
    if (count($week) === 7) {
        $weeks[] = $week;
        $week = [];
    }
}
if (!empty($week)) {
    while (count($week) < 7) {
        $week[] = null;
    }
    $weeks[] = $week;
}

require_once __DIR__ . '/includes/header.php';
$isAdmin = session_is_admin();
$isSuperAdmin = strtoupper($_SESSION['call_sign']) === ADMIN_CALL_SIGN;
?>
<main style="max-width:860px;">
    <h2 style="display:flex;align-items:center;justify-content:center;gap:16px;">
        <a href="<?php echo h($prevUrl); ?>" class="month-wedge" title="Previous month">&#9664;</a>
        <span><?php echo h($monthYearName); ?></span>
        <a href="<?php echo h($nextUrl); ?>" class="month-wedge" title="Next month">&#9654;</a>
    </h2>
    <p style="text-align:center;margin-top:-10px;">
        <a class="btn btn-secondary" style="margin-top:0;padding:5px 14px;font-size:0.78rem;" href="schedule.php">Today</a>
    </p>
    <p style="color:#55677d;font-size:0.85rem;margin-top:-6px;text-align:center;white-space:nowrap;overflow-x:auto;">
        Click a day to reserve one of the radios (Skokie, Northfield, Northbrook, MunAV640, MunEndfed).
    </p>

    <table class="calendar">
        <thead>
            <tr>
                <?php foreach ($dayNames as $name): ?>
                    <th><?php echo h($name); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($weeks as $week): ?>
                <tr>
                    <?php foreach ($week as $day): ?>
                        <?php if ($day === null): ?>
                            <td class="empty"></td>
                        <?php else: ?>
                            <?php
                                $isToday = ($day === $realDay && $month === $realMonth && $year === $realYear);
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            ?>
                            <td class="<?php echo $isToday ? 'today' : ''; ?>">
                                <a href="schedule_day.php?date=<?php echo h($dateStr); ?>"><?php echo $day; ?></a>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="actions-row">
        <a class="btn btn-secondary" href="welcome.php">Welcome Page</a>
        <a class="btn btn-secondary" href="radio_control.php">Radio Activation</a>
        <a class="btn btn-secondary" href="my_account.php">My Account</a>
        <a class="btn btn-secondary" href="my_reservations.php">My Reservations</a>
        <a class="btn btn-secondary" href="all_reservations.php">All Reservations</a>
        <?php if ($isAdmin): ?>
            <a class="btn btn-secondary" href="admin.php">Admin</a>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
            <a class="btn btn-secondary" href="pi_status.php">Pi Status</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="logout.php">Log Out</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
