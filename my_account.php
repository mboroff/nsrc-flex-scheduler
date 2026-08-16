<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
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

$usStates = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
    'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
    'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
    'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
    'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
    'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
    'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
    'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
    'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
    'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
    'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
    'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
    'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
];
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

        <label for="zip_code">Zip Code</label>
        <input type="text" id="zip_code" name="zip_code" required inputmode="numeric"
               maxlength="5" autocomplete="postal-code"
               value="<?php echo h($account['zip_code']); ?>">
        <span id="zip_status" style="display:block;font-size:0.85rem;color:#55677d;"></span>

        <label for="city">City</label>
        <input type="text" id="city" name="city" required value="<?php echo h($account['city']); ?>">

        <label for="state">State</label>
        <?php $currentState = strtoupper(trim($account['state'])); ?>
        <select id="state" name="state" required>
            <option value="">-- Select State --</option>
            <?php foreach ($usStates as $abbr => $name): ?>
                <option value="<?php echo h($abbr); ?>" <?php echo $currentState === $abbr ? 'selected' : ''; ?>><?php echo h($name); ?> (<?php echo h($abbr); ?>)</option>
            <?php endforeach; ?>
        </select>

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

    <script>
        // Same free, keyless api.zippopotam.us City/State autofill used
        // on create_account.php and admin.php. Runs in the member's own
        // browser, so it works even if the Pi itself has no outbound
        // internet access. Both fields stay editable afterward.
        (function () {
            var zipInput = document.getElementById('zip_code');
            var cityInput = document.getElementById('city');
            var stateSelect = document.getElementById('state');
            var statusEl = document.getElementById('zip_status');
            var lastLookedUp = '';

            function lookupZip() {
                var zip = zipInput.value.trim();
                if (!/^\d{5}$/.test(zip) || zip === lastLookedUp) {
                    return;
                }
                lastLookedUp = zip;
                statusEl.textContent = 'Looking up city/state...';

                fetch('https://api.zippopotam.us/us/' + zip)
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('not found');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        var place = data.places && data.places[0];
                        if (!place) {
                            throw new Error('no place data');
                        }
                        cityInput.value = place['place name'];
                        var abbr = place['state abbreviation'];
                        if (abbr && stateSelect.querySelector('option[value="' + abbr + '"]')) {
                            stateSelect.value = abbr;
                        }
                        statusEl.textContent = 'City and State filled in \u2014 feel free to correct if needed.';
                    })
                    .catch(function () {
                        statusEl.textContent = 'Could not look up that Zip Code \u2014 please enter City and State manually.';
                    });
            }

            zipInput.addEventListener('blur', lookupZip);
            zipInput.addEventListener('input', function () {
                if (/^\d{5}$/.test(zipInput.value.trim())) {
                    lookupZip();
                }
            });
        })();
    </script>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
