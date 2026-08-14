<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Create Account';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callSign  = normalize_call_sign($_POST['call_sign'] ?? '');
    $password  = $_POST['password'] ?? '';
    $verify    = $_POST['verify_password'] ?? '';
    $email     = trim($_POST['email'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $street    = trim($_POST['street_address'] ?? '');
    $city      = trim($_POST['city'] ?? '');
    $state     = trim($_POST['state'] ?? '');
    $zip       = trim($_POST['zip_code'] ?? '');
    $phone     = trim($_POST['phone_number'] ?? '');

    $required = [$callSign, $password, $verify, $email, $firstName, $lastName, $street, $city, $state, $zip, $phone];
    if (in_array('', $required, true)) {
        $error = 'All fields are required.';
    } elseif ($password !== $verify) {
        $error = 'Password and verification do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db = get_db();
        $check = $db->prepare('SELECT id FROM users WHERE call_sign = ?');
        $check->execute([$callSign]);

        if ($check->fetch()) {
            $error = 'That Call Sign already has an account.';
        } else {
            $memberCheck = $db->prepare('SELECT is_current FROM members WHERE call_sign = ?');
            $memberCheck->execute([$callSign]);
            $member = $memberCheck->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $error = 'That Call Sign was not found on the North Shore Radio Club membership list.';
            } elseif (!$member['is_current']) {
                $error = 'Membership dues for that Call Sign are not current. Please renew with the club before creating an account.';
            } else {
                $now  = date('Y-m-d H:i:s'); // system time, stored but never shown
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $db->prepare(
                    'INSERT INTO users (call_sign, password_hash, email, first_name, last_name,
                                         street_address, city, state, zip_code, phone_number,
                                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([$callSign, $hash, $email, $firstName, $lastName,
                                   $street, $city, $state, $zip, $phone, $now, $now]);

                header('Location: index.php?msg=account_created');
                exit;
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <h2>Create Account</h2>

    <?php if ($error): ?>
        <div class="message error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="create_account.php">
        <label for="call_sign">Call Sign</label>
        <input type="text" id="call_sign" name="call_sign" required
               value="<?php echo isset($_POST['call_sign']) ? h($_POST['call_sign']) : ''; ?>">

        <label for="first_name">First Name</label>
        <input type="text" id="first_name" name="first_name" required
               value="<?php echo isset($_POST['first_name']) ? h($_POST['first_name']) : ''; ?>">

        <label for="last_name">Last Name</label>
        <input type="text" id="last_name" name="last_name" required
               value="<?php echo isset($_POST['last_name']) ? h($_POST['last_name']) : ''; ?>">

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required
               value="<?php echo isset($_POST['email']) ? h($_POST['email']) : ''; ?>">

        <label for="street_address">Street Address</label>
        <input type="text" id="street_address" name="street_address" required
               value="<?php echo isset($_POST['street_address']) ? h($_POST['street_address']) : ''; ?>">

        <label for="city">City</label>
        <input type="text" id="city" name="city" required
               value="<?php echo isset($_POST['city']) ? h($_POST['city']) : ''; ?>">

        <label for="state">State</label>
        <input type="text" id="state" name="state" required
               value="<?php echo isset($_POST['state']) ? h($_POST['state']) : ''; ?>">

        <label for="zip_code">Zip Code</label>
        <input type="text" id="zip_code" name="zip_code" required
               value="<?php echo isset($_POST['zip_code']) ? h($_POST['zip_code']) : ''; ?>">

        <label for="phone_number">Phone Number</label>
        <input type="text" id="phone_number" name="phone_number" required
               value="<?php echo isset($_POST['phone_number']) ? h($_POST['phone_number']) : ''; ?>">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">

        <label for="verify_password">Verify Password</label>
        <input type="password" id="verify_password" name="verify_password" required autocomplete="new-password">

        <button type="submit">Create Account</button>
    </form>

    <div class="actions-row">
        <a class="btn btn-secondary" href="index.php">Back to Login</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
