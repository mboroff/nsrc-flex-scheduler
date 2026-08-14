<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Login';
$error = '';
$success = '';

// A page redirected here (e.g. after changing password or creating an
// account) can pass a one-time success message via the query string.
if (isset($_GET['msg']) && $_GET['msg'] === 'pw_changed') {
    $success = 'Password updated. Please log in with your new password.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'account_created') {
    $success = 'Account created. Please log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $callSign = normalize_call_sign($_POST['call_sign'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($callSign === '' || $password === '') {
        $error = 'Please enter both Call Sign and Password.';
    } else {
        $stmt = get_db()->prepare('SELECT * FROM users WHERE call_sign = ?');
        $stmt->execute([$callSign]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Call Sign not found';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect password';
        } else {
            // Success - record the login time, start the session, and go
            // to the scheduling calendar.
            $update = get_db()->prepare('UPDATE users SET last_login = ? WHERE id = ?');
            $update->execute([date('Y-m-d H:i:s'), $user['id']]);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['call_sign'] = $user['call_sign'];
            $_SESSION['is_admin']  = ($user['is_admin'] === 'Y');
            header('Location: schedule.php');
            exit;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <h2>Member Login</h2>

    <?php if ($error): ?>
        <div class="message error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <form method="post" action="index.php">
        <input type="hidden" name="action" value="login">

        <label for="call_sign">Call Sign</label>
        <input type="text" id="call_sign" name="call_sign" required autocomplete="username"
               value="<?php echo isset($_POST['call_sign']) ? h($_POST['call_sign']) : ''; ?>">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit">Login</button>
    </form>

    <div class="actions-row">
        <a class="btn btn-secondary" href="create_account.php">Create Account</a>
        <a class="btn btn-secondary" href="change_password.php">Change Password</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
