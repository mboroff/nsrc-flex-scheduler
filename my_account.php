<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'My Account';
$db = get_db();
$userId = $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newCallSign = normalize_call_sign($_POST['call_sign'] ?? '');
    $newEmail    = trim($_POST['email'] ?? '');
    $firstName   = trim($_POST['first_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $street      = trim($_POST['street_address'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $state       = trim($_POST['state'] ?? '');
    $zip         = trim($_POST['zip_code'] ?? '');
    $phone       = trim($_POST['phone_number'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $row = $db->prepare('SELECT * FROM users WHERE id = ?');
    $row->execute([$userId]);
    $account = $row->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        header('Location: logout.php');
        exit;
    }

    if ($currentPassword === '' || !password_verify($currentPassword, $account['password_hash'])) {
        $error = 'Current Password is required and must be correct to save changes.';
    } elseif ($newCallSign === '' || $newEmail === '' || $firstName === '' || $lastName === ''
              || $street === '' || $city === '' || $state === '' || $zip === '' || $phone === '') {
        $error = 'All fields except New Password are required.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $error = 'New Password and Confirm New Password do not match.';
    } else {
        $dupe = $db->prepare('SELECT id FROM users WHERE call_sign = ? AND id != ?');
        $dupe->execute([$newCallSign, $userId]);

        if ($dupe->fetch()) {
            $error = 'Another account already uses that Call Sign.';
        } else {
            $now = date('Y-m-d H:i:s');
            $fields = 'call_sign = ?, email = ?, first_name = ?, last_name = ?, street_address = ?, city = ?, state = ?, zip_code = ?, phone_number = ?, updated_at = ?';
            $params = [$newCallSign, $newEmail, $firstName, $lastName, $street, $city, $state, $zip, $phone, $now];
            if ($newPassword !== '') {
                $fields = 'password_hash = ?, ' . $fields;
                array_unshift($params, password_hash($newPassword, PASSWORD_DEFAULT));
            }
            $params[] = $userId;
            $update = $db->prepare("UPDATE users SET $fields WHERE id = ?");
            $update->execute($params);

            $_SESSION['call_sign'] = $newCallSign;
            $success = 'Account updated.';
        }
    }
}

$row = $db->prepare('SELECT * FROM users WHERE id = ?');
$row->execute([$userId]);
$account = $row->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    header('Location: logout.php');
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <h2>My Account</h2>

    <?php if ($error): ?>
        <div class="message error"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="message success"><?php echo h($success); ?></div>
    <?php endif; ?>

    <p style="color:#55677d;font-size:0.85rem;font-family:var(--font-mono);">
        Created: <?php echo h($account['created_at']); ?><br>
        Last Updated: <?php echo h($account['updated_at']); ?><br>
        Last Login: <?php echo h($account['last_login'] ?? '—'); ?>
    </p>

    <form method="post" action="my_account.php">
        <label for="call_sign">Call Sign</label>
        <input type="text" id="call_sign" name="call_sign" required value="<?php echo h($account['call_sign']); ?>">

        <label for="first_name">First Name</label>
        <input type="text" id="first_name" name="first_name" required value="<?php echo h($account['first_name']); ?>">

        <label for="last_name">Last Name</label>
        <input type="text" id="last_name" name="last_name" required value="<?php echo h($account['last_name']); ?>">

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required value="<?php echo h($account['email']); ?>">

        <label for="street_address">Street Address</label>
        <input type="text" id="street_address" name="street_address" required value="<?php echo h($account['street_address']); ?>">

        <label for="city">City</label>
        <input type="text" id="city" name="city" required value="<?php echo h($account['city']); ?>">

        <label for="state">State</label>
        <input type="text" id="state" name="state" required value="<?php echo h($account['state']); ?>">

        <label for="zip_code">Zip Code</label>
        <input type="text" id="zip_code" name="zip_code" required value="<?php echo h($account['zip_code']); ?>">

        <label for="phone_number">Phone Number</label>
        <input type="text" id="phone_number" name="phone_number" required value="<?php echo h($account['phone_number']); ?>">

        <label for="new_password">New Password (leave blank to keep current)</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password">

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">

        <label for="current_password">Current Password (required to save any change)</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">

        <button type="submit">Update Account</button>
    </form>

    <div class="actions-row">
        <a class="btn btn-secondary" href="schedule.php">Return to Calendar</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
