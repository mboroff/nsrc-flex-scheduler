<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Welcome';

// Pick a greeting based on the server's current local time.
$hour = (int) date('G'); // 0-23, no leading zero
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 18) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

$firstName = $_SESSION['first_name'] ?? '';
$lastName  = $_SESSION['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);
if ($fullName === '') {
    // Fall back to Call Sign if we don't have a name on file.
    $fullName = $_SESSION['call_sign'];
}

require_once __DIR__ . '/includes/header.php';
$isAdmin      = session_is_admin();
$isSuperAdmin = strtoupper($_SESSION['call_sign']) === ADMIN_CALL_SIGN;
?>
<main style="max-width:860px;">
    <h2 style="text-align:center;"><?php echo h($greeting); ?>, <?php echo h($fullName); ?></h2>
    <p style="text-align:center;color:#55677d;font-size:0.95rem;margin-top:-10px;">
        What can I do for you today?
    </p>

    <div class="actions-row">
        <a class="btn btn-secondary" href="schedule.php">Scheduling Calendar</a>
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
