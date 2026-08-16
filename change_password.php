<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
require_once __DIR__ . '/config.php';

$pageTitle = 'Change Password';
$error = '';

// Reachable from the login page before a session exists, so we ask for the
// Call Sign here too in order to know whose password to check/update.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callSign    = normalize_call_sign($_POST['call_sign'] ?? '');
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if ($callSign === '' || $oldPassword === '' || $newPassword === '' || $confirm === '') {
        $error = 'All fields are required.';
    } else {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM users WHERE call_sign = ?');
        $stmt->execute([$callSign]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Call Sign not found';
        } elseif (!password_verify($oldPassword, $user['password_hash'])) {
            $error = 'Incorrect password';
        } elseif ($newPassword !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $update = $db->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $update->execute([$newHash, $now, $user['id']]);

            header('Location: index.php?msg=pw_changed');
            exit;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <h2>Change Password</h2>

    <?php if ($error): ?>
        <div class="message error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="change_password.php">
        <label for="call_sign">Call Sign</label>
        <input type="text" id="call_sign" name="call_sign" required
               value="<?php echo isset($_POST['call_sign']) ? h($_POST['call_sign']) : ''; ?>">

        <label for="old_password">Old Password</label>
        <input type="password" id="old_password" name="old_password" required autocomplete="current-password">

        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required autocomplete="new-password">

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">

        <button type="submit">Update Password</button>
    </form>

    <div class="actions-row">
        <a class="btn btn-secondary" href="index.php">Back to Login</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
