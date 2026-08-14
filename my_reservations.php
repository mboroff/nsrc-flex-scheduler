<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'My Reservations';
$db = get_db();

$stmt = $db->prepare(
    'SELECT reservations.start_time, reservations.end_time, radios.name AS radio_name
     FROM reservations
     JOIN radios ON radios.id = reservations.radio_id
     WHERE reservations.user_id = ?
     ORDER BY reservations.start_time ASC'
);
$stmt->execute([$_SESSION['user_id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:640px;">
    <h2>My Reservations</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        Every radio reservation booked under <strong><?php echo h($_SESSION['call_sign']); ?></strong>.
    </p>

    <?php if (empty($rows)): ?>
        <p style="color:#55677d;">You don't have any reservations yet.</p>
    <?php else: ?>
        <table class="list-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Radio</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $start = DateTime::createFromFormat('Y-m-d H:i:s', $row['start_time']);
                        $end   = DateTime::createFromFormat('Y-m-d H:i:s', $row['end_time']);
                        $isPast = $start < $now;
                    ?>
                    <tr class="<?php echo $isPast ? 'past-row' : ''; ?>">
                        <td><a href="schedule_day.php?date=<?php echo h($start->format('Y-m-d')); ?>"><?php echo h($start->format('D, M j, Y')); ?></a></td>
                        <td class="ts"><?php echo h($start->format('g:i A') . ' - ' . $end->format('g:i A')); ?></td>
                        <td><?php echo h($row['radio_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="actions-row">
        <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
        <a class="btn btn-secondary" href="all_reservations.php">All Reservations</a>
        <a class="btn btn-secondary" href="logout.php">Log Out</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
