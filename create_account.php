<?php
// Created by Claude.AI
// For
// Marty Boroff - WD9GYM
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
        } elseif ($callSign === ADMIN_CALL_SIGN) {
            // The super-admin Call Sign is exempt from the membership
            // list check, since they need to be able to create their
            // account even before/without appearing on the roster.
            $now  = date('Y-m-d H:i:s');
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

        <label for="zip_code">Zip Code</label>
        <input type="text" id="zip_code" name="zip_code" required inputmode="numeric"
               maxlength="5" autocomplete="postal-code"
               value="<?php echo isset($_POST['zip_code']) ? h($_POST['zip_code']) : ''; ?>">
        <span id="zip_status" style="display:block;font-size:0.85rem;color:#55677d;"></span>

        <label for="city">City</label>
        <input type="text" id="city" name="city" required
               value="<?php echo isset($_POST['city']) ? h($_POST['city']) : ''; ?>">

        <label for="state">State</label>
        <?php $selectedState = isset($_POST['state']) ? strtoupper(trim($_POST['state'])) : ''; ?>
        <select id="state" name="state" required>
            <option value="">-- Select State --</option>
            <?php
            $states = [
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
            foreach ($states as $abbr => $name) {
                $sel = $selectedState === $abbr ? ' selected' : '';
                echo '<option value="' . h($abbr) . '"' . $sel . '>' . h($name) . ' (' . h($abbr) . ')</option>';
            }
            ?>
        </select>

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

    <script>
        // Looks up City/State from a 5-digit Zip using the free, keyless
        // Zippopotam.us API (runs in the member's own browser, so this
        // works even on a Pi with no outbound internet access). City and
        // State stay editable in case the lookup is wrong or unavailable.
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
