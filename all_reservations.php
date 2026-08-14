<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'All Reservations';
$db = get_db();

$now = date('Y-m-d H:i:s');
$stmt = $db->prepare(
    "SELECT reservations.start_time, reservations.end_time, radios.name AS radio_name,
            COALESCE(users.call_sign, reservations.call_sign_snapshot) AS call_sign
     FROM reservations
     JOIN radios ON radios.id = reservations.radio_id
     LEFT JOIN users ON users.id = reservations.user_id
     WHERE reservations.end_time > ?
     ORDER BY reservations.start_time ASC"
);
$stmt->execute([$now]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:720px;">
    <h2>All Reservations</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        Every current and upcoming radio reservation booked by anyone in the group.
    </p>

    <?php if (empty($rows)): ?>
        <p style="color:#55677d;">There are no reservations yet.</p>
    <?php else: ?>
        <table class="list-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Radio</th>
                    <th>Call Sign</th>
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
                        <td class="ts"><?php echo h($row['call_sign']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="actions-row">
        <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
        <a class="btn btn-secondary" href="my_reservations.php">My Reservations</a>
        <a class="btn btn-secondary" href="logout.php">Log Out</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
