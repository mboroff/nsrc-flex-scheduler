<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Restricted to one Call Sign, regardless of who's logged in.
if (!session_is_admin()) {
    header('Location: schedule.php');
    exit;
}

$pageTitle = 'Admin';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'clear_past_reservations') {
        $now = date('Y-m-d H:00:00');
        $delete = $db->prepare('DELETE FROM reservations WHERE start_time < ?');
        $delete->execute([$now]);
        $count = $delete->rowCount();
        $_SESSION['flash_success'] = "Removed $count reservation(s) before the current hour.";
        header('Location: admin.php');
        exit;
    }

    if ($action === 'toggle_admin') {
        $target = $db->prepare('SELECT call_sign, is_admin FROM users WHERE id = ?');
        $target->execute([$userId]);
        $targetUser = $target->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            $_SESSION['flash_error'] = 'Account not found.';
        } elseif (strtoupper($targetUser['call_sign']) === ADMIN_CALL_SIGN) {
            $_SESSION['flash_error'] = 'WD9GYM is always the super-admin and cannot be changed here.';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'You cannot revoke your own admin status.';
        } else {
            $newValue = $targetUser['is_admin'] === 'Y' ? '' : 'Y';
            $update = $db->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
            $update->execute([$newValue, $userId]);
            $_SESSION['flash_success'] = $targetUser['call_sign'] . ' is now ' . ($newValue === 'Y' ? 'an admin' : 'no longer an admin')
                . '. Takes effect next time they log in.';
        }
        header('Location: admin.php');
        exit;
    }

    if ($action === 'delete') {
        if ($userId === (int) $_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'You cannot delete your own account while logged in as it.';
        } else {
            // Reservations are intentionally left in place - they keep
            // showing the Call Sign that made them (call_sign_snapshot)
            // even after the account itself is gone.
            $delete = $db->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$userId]);
            $_SESSION['flash_success'] = 'Account deleted. Their existing reservations remain on the schedule.';
        }
    } elseif ($action === 'update') {
        $newCallSign = normalize_call_sign($_POST['call_sign'] ?? '');
        $newEmail    = trim($_POST['email'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $firstName   = trim($_POST['first_name'] ?? '');
        $lastName    = trim($_POST['last_name'] ?? '');
        $street      = trim($_POST['street_address'] ?? '');
        $city        = trim($_POST['city'] ?? '');
        $state       = trim($_POST['state'] ?? '');
        $zip         = trim($_POST['zip_code'] ?? '');
        $phone       = trim($_POST['phone_number'] ?? '');

        if ($newCallSign === '' || $newEmail === '') {
            $_SESSION['flash_error'] = 'Call Sign and Email cannot be blank.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'That email address is not valid.';
        } else {
            $dupe = $db->prepare('SELECT id FROM users WHERE call_sign = ? AND id != ?');
            $dupe->execute([$newCallSign, $userId]);

            if ($dupe->fetch()) {
                $_SESSION['flash_error'] = 'Another account already uses that Call Sign.';
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
                $_SESSION['flash_success'] = 'Account updated.';
            }
        }
    }

    header('Location: admin.php');
    exit;
}

$flashError   = $_SESSION['flash_error'] ?? '';
$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$users = $db->query('SELECT * FROM users ORDER BY call_sign')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:1140px;">
    <h2>Admin &mdash; Manage Accounts</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        Logged in as <strong><?php echo h($_SESSION['call_sign']); ?></strong>. Edit any field and
        click Update, or click Delete to remove an account. Leave New Password blank to keep the
        current password.
    </p>

    <?php if ($flashError): ?>
        <div class="message error"><?php echo h($flashError); ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="message success"><?php echo h($flashSuccess); ?></div>
    <?php endif; ?>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Call Sign</th>
                <th>Email</th>
                <th>New Password</th>
                <th>Created</th>
                <th>Updated</th>
                <th>Last Login</th>
                <th>Admin?</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $id = (int) $u['id']; ?>
                <!-- Two forms per row, associated to their cells via the
                     form="" attribute (valid HTML - a <form> can't wrap
                     partial table markup, but inputs elsewhere on the page
                     can still belong to it this way). -->
                <form id="upd-<?php echo $id; ?>" method="post" action="admin.php"></form>
                <form id="del-<?php echo $id; ?>" method="post" action="admin.php"></form>
                <form id="adm-<?php echo $id; ?>" method="post" action="admin.php">
                    <input type="hidden" name="action" value="toggle_admin">
                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                </form>
                <tr>
                    <td>
                        <input form="upd-<?php echo $id; ?>" type="text" name="call_sign"
                               value="<?php echo h($u['call_sign']); ?>">
                    </td>
                    <td>
                        <input form="upd-<?php echo $id; ?>" type="email" name="email"
                               value="<?php echo h($u['email']); ?>">
                    </td>
                    <td>
                        <input form="upd-<?php echo $id; ?>" type="password" name="new_password"
                               placeholder="(leave blank)" autocomplete="new-password">
                    </td>
                    <td class="ts"><?php echo h($u['created_at']); ?></td>
                    <td class="ts"><?php echo h($u['updated_at']); ?></td>
                    <td class="ts"><?php echo h($u['last_login'] ?? '—'); ?></td>
                    <td>
                        <?php if (strtoupper($u['call_sign']) === ADMIN_CALL_SIGN): ?>
                            Super
                        <?php else: ?>
                            <button form="adm-<?php echo $id; ?>" type="submit" class="btn btn-small <?php echo $u['is_admin'] === 'Y' ? '' : 'btn-secondary'; ?>">
                                <?php echo $u['is_admin'] === 'Y' ? 'Revoke Admin' : 'Grant Admin'; ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td class="admin-actions">
                        <input type="hidden" form="upd-<?php echo $id; ?>" name="action" value="update">
                        <input type="hidden" form="upd-<?php echo $id; ?>" name="user_id" value="<?php echo $id; ?>">
                        <button form="upd-<?php echo $id; ?>" type="submit" class="btn btn-small">Update</button>

                        <input type="hidden" form="del-<?php echo $id; ?>" name="action" value="delete">
                        <input type="hidden" form="del-<?php echo $id; ?>" name="user_id" value="<?php echo $id; ?>">
                        <?php $isSelf = $id === (int) $_SESSION['user_id']; ?>
                        <button type="button" class="btn btn-danger btn-small"
                                <?php echo $isSelf ? 'disabled title="You can\'t delete the account you\'re logged in as."' : ''; ?>
                                onclick="confirmDelete('del-<?php echo $id; ?>', <?php echo htmlspecialchars(json_encode($u['call_sign']), ENT_QUOTES); ?>)">
                            Delete
                        </button>
                    </td>
                </tr>
                <tr class="admin-subrow">
                    <td></td>
                    <td colspan="7">
                        <span class="admin-subfield">First
                            <input form="upd-<?php echo $id; ?>" type="text" name="first_name" placeholder="First Name" value="<?php echo h($u['first_name'] ?? ''); ?>">
                        </span>
                        <span class="admin-subfield">Last
                            <input form="upd-<?php echo $id; ?>" type="text" name="last_name" placeholder="Last Name" value="<?php echo h($u['last_name'] ?? ''); ?>">
                        </span>
                        <span class="admin-subfield">Phone
                            <input form="upd-<?php echo $id; ?>" type="text" name="phone_number" placeholder="Phone" value="<?php echo h($u['phone_number'] ?? ''); ?>">
                        </span>
                    </td>
                </tr>
                <tr class="admin-subrow">
                    <td></td>
                    <td colspan="7">
                        <span class="admin-subfield">Street
                            <input form="upd-<?php echo $id; ?>" type="text" name="street_address" placeholder="Street Address" value="<?php echo h($u['street_address'] ?? ''); ?>">
                        </span>
                        <span class="admin-subfield">City
                            <input form="upd-<?php echo $id; ?>" type="text" name="city" placeholder="City" value="<?php echo h($u['city'] ?? ''); ?>">
                        </span>
                        <span class="admin-subfield">State
                            <input form="upd-<?php echo $id; ?>" type="text" name="state" placeholder="State" value="<?php echo h($u['state'] ?? ''); ?>">
                        </span>
                        <span class="admin-subfield">Zip
                            <input form="upd-<?php echo $id; ?>" type="text" name="zip_code" placeholder="Zip" value="<?php echo h($u['zip_code'] ?? ''); ?>">
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="actions-row">
        <a class="btn btn-secondary" href="members_import.php">Membership List</a>
        <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
        <a class="btn btn-secondary" href="logout.php">Log Out</a>
    </div>

    <div class="actions-row">
        <form method="post" action="admin.php"
              onsubmit="return confirm('Remove all reservations before the current date and hour? This cannot be undone.');">
            <input type="hidden" name="action" value="clear_past_reservations">
            <button type="submit" class="btn btn-danger">Remove Past Reservations</button>
        </form>
    </div>

    <div id="confirm-overlay" class="confirm-overlay">
        <div class="confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="confirm-text">
            <p id="confirm-text">Are you sure?</p>
            <div class="confirm-buttons">
                <button type="button" class="btn btn-danger" onclick="confirmModalAnswer(true)">Y</button>
                <button type="button" class="btn btn-secondary" onclick="confirmModalAnswer(false)">N</button>
            </div>
        </div>
    </div>

    <script>
        var pendingDeleteFormId = null;

        function confirmDelete(formId, callSign) {
            pendingDeleteFormId = formId;
            document.getElementById('confirm-text').textContent =
                'Delete ' + callSign + '? Their existing reservations will remain on the schedule. Y/N';
            document.getElementById('confirm-overlay').classList.add('is-visible');
        }

        function confirmModalAnswer(yes) {
            document.getElementById('confirm-overlay').classList.remove('is-visible');
            if (yes && pendingDeleteFormId) {
                document.getElementById(pendingDeleteFormId).submit();
            }
            pendingDeleteFormId = null;
        }
    </script>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
