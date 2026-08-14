<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (!session_is_admin()) {
    header('Location: schedule.php');
    exit;
}

$pageTitle = 'Membership List';
$db = get_db();
$flashError = '';
$flashSuccess = '';

/**
 * Is this member current on dues, given the value found in the column
 * whose header is this calendar year (e.g. "2026")?
 *   - blank                  -> NOT current
 *   - value equals the year  -> current            (2026 col, "2026")
 *   - value equals year + 1  -> current (paid ahead) (2026 col, "2027")
 *   - anything else          -> NOT current          (2026 col, "2025", or junk)
 */
function is_dues_current(string $cellValue, int $columnYear): bool {
    $cellValue = trim($cellValue);
    if ($cellValue === '') {
        return false;
    }
    $asInt = (int) $cellValue;
    return $asInt === $columnYear || $asInt === $columnYear + 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    $callSign = normalize_call_sign($_POST['call_sign'] ?? '');
    $row = $db->prepare('SELECT is_current FROM members WHERE call_sign = ?');
    $row->execute([$callSign]);
    $current = $row->fetchColumn();
    if ($current !== false) {
        $newState = $current ? 0 : 1;
        $update = $db->prepare(
            "UPDATE members SET is_current = ?, overridden = 1, comments = 'Admin override (" . date('Y-m-d') . ")' WHERE call_sign = ?"
        );
        $update->execute([$newState, $callSign]);
        $_SESSION['flash_success'] = "$callSign marked " . ($newState ? 'current' : 'not current') . ' by admin override.';
    }
    header('Location: members_import.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $callSign = normalize_call_sign($_POST['new_call_sign'] ?? '');
    if ($callSign === '') {
        $_SESSION['flash_error'] = 'Call Sign is required to add a member manually.';
    } else {
        $upsert = $db->prepare(
            "INSERT INTO members (call_sign, first_name, last_name, dues_column, dues_value, is_current, overridden, comments, imported_at)
             VALUES (?, '', '', ?, 'Admin override', 1, 1, ?, ?)
             ON CONFLICT(call_sign) DO UPDATE SET is_current = 1, overridden = 1, comments = excluded.comments, imported_at = excluded.imported_at"
        );
        $upsert->execute([$callSign, (string) date('Y'), 'Added/marked current by admin override (' . date('Y-m-d') . ')', date('Y-m-d H:i:s')]);
        $_SESSION['flash_success'] = "$callSign added and marked current.";
    }
    header('Location: members_import.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['roster'])) {
    $tmpPath = $_FILES['roster']['tmp_name'] ?? '';
    if (!$tmpPath || !is_uploaded_file($tmpPath)) {
        $flashError = 'No file was uploaded.';
    } else {
        $rows = [];
        if (($handle = fopen($tmpPath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }

        if (count($rows) < 2) {
            $flashError = 'That file did not look like a CSV with a header row and data.';
        } else {
            $header = array_map('trim', $rows[0]);
            $headerLower = array_map('strtolower', $header);

            $callSignIdx = array_search('call sign', $headerLower, true);
            $firstNameIdx = array_search('first name', $headerLower, true);
            $lastNameIdx = array_search('last name', $headerLower, true);
            $lastColIdx = count($header) - 1; // last column doubles as comments

            $thisYear = (int) date('Y');
            $yearColIdx = null;
            foreach ($header as $idx => $col) {
                if (preg_match('/^\d{4}$/', $col) && (int) $col === $thisYear) {
                    $yearColIdx = $idx;
                    break;
                }
            }

            if ($callSignIdx === false) {
                $flashError = 'Could not find a "Call sign" column in that file.';
            } elseif ($yearColIdx === null) {
                $flashError = "Could not find a column for the current year ($thisYear). Nobody can be validated until that column exists.";
            } else {
                // Full replace: every upload starts clean. Any admin
                // override from before this upload is intentionally NOT
                // preserved - re-apply it after uploading if still needed.
                $db->exec('DELETE FROM members');

                $now = date('Y-m-d H:i:s');
                $imported = 0;
                $upsert = $db->prepare(
                    'INSERT INTO members (call_sign, first_name, last_name, dues_column, dues_value, is_current, overridden, comments, imported_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
                     ON CONFLICT(call_sign) DO UPDATE SET
                        first_name = excluded.first_name,
                        last_name = excluded.last_name,
                        dues_column = excluded.dues_column,
                        dues_value = excluded.dues_value,
                        is_current = CASE WHEN members.overridden = 1 THEN members.is_current ELSE excluded.is_current END,
                        comments = CASE WHEN members.overridden = 1 THEN members.comments ELSE excluded.comments END,
                        imported_at = excluded.imported_at'
                );

                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $callSign = normalize_call_sign($row[$callSignIdx] ?? '');
                    if ($callSign === '') {
                        continue;
                    }
                    $duesValue = $row[$yearColIdx] ?? '';
                    $current = is_dues_current($duesValue, $thisYear);
                    $upsert->execute([
                        $callSign,
                        $firstNameIdx !== false ? trim($row[$firstNameIdx] ?? '') : '',
                        $lastNameIdx !== false ? trim($row[$lastNameIdx] ?? '') : '',
                        (string) $thisYear,
                        trim($duesValue),
                        $current ? 1 : 0,
                        trim($row[$lastColIdx] ?? ''),
                        $now,
                    ]);
                    $imported++;
                }
                $flashSuccess = "Imported $imported member(s), checked against the $thisYear column.";
            }
        }
    }
}

$flashError = $flashError ?: ($_SESSION['flash_error'] ?? '');
$flashSuccess = $flashSuccess ?: ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$members = $db->query('SELECT * FROM members ORDER BY call_sign')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>
<main style="max-width:820px;">
    <h2>Membership List</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        Export the club roster from Google Sheets as CSV (File &rarr; Download &rarr; Comma
        Separated Values) and upload it here. New account creation checks this list.
    </p>

    <?php if ($flashError): ?>
        <div class="message error"><?php echo h($flashError); ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="message success"><?php echo h($flashSuccess); ?></div>
    <?php endif; ?>

    <form method="post" action="members_import.php" enctype="multipart/form-data">
        <label for="roster">Roster CSV file</label>
        <input type="file" id="roster" name="roster" accept=".csv" required>
        <button type="submit">Upload &amp; Import</button>
    </form>

    <div class="actions-row">
        <a class="btn btn-secondary" href="admin.php">Back to Admin</a>
        <a class="btn btn-secondary" href="schedule.php">Back to Calendar</a>
    </div>

    <h2 style="margin-top:28px;">Manually Mark a Call Sign Current</h2>
    <p style="color:#55677d;font-size:0.9rem;margin-top:-8px;">
        For dues paid but not yet reflected on the spreadsheet. A full CSV upload replaces
        the entire list, including any overrides - re-apply this after uploading if still needed.
    </p>
    <form method="post" action="members_import.php">
        <input type="hidden" name="action" value="add">
        <label for="new_call_sign">Call Sign</label>
        <input type="text" id="new_call_sign" name="new_call_sign" required>
        <button type="submit">Add / Mark Current</button>
    </form>

    <table class="admin-table" style="margin-top:24px;">
        <thead>
            <tr>
                <th>Call Sign</th><th>Name</th><th>Dues Column</th><th>Value</th><th>Current?</th><th>Comments</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td class="ts"><?php echo h($m['call_sign']); ?></td>
                    <td><?php echo h(trim($m['first_name'] . ' ' . $m['last_name'])); ?></td>
                    <td class="ts"><?php echo h($m['dues_column']); ?></td>
                    <td class="ts"><?php echo h($m['dues_value']); ?></td>
                    <td><?php echo $m['is_current'] ? 'Yes' : 'No'; ?><?php echo $m['overridden'] ? ' <span style="color:#a15c07;">(override)</span>' : ''; ?></td>
                    <td><?php echo h($m['comments']); ?></td>
                    <td>
                        <form method="post" action="members_import.php" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="call_sign" value="<?php echo h($m['call_sign']); ?>">
                            <button type="submit" class="btn btn-small btn-secondary">
                                Mark <?php echo $m['is_current'] ? 'Not Current' : 'Current'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="actions-row">
        <a class="btn btn-secondary" href="admin.php">Back to Admin</a>
        <a class="btn btn-secondary" href="schedule.php">Calendar</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
