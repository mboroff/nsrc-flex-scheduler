# North Shore Radio Club Flex-Cadre Site

Login / account-creation / password-change site for the North Shore
Radio Club Flex-Cadre
group, built to run on a Raspberry Pi with Apache already installed.

## What's included

| File | Purpose |
|---|---|
| `index.php` | Welcome page + login form |
| `create_account.php` | New account form, validated against the club membership roster |
| `change_password.php` | Old/New/Confirm password form |
| `my_account.php` | Self-service page: view/edit your own profile, requires current password to save |
| `admin.php` | Admin-only: list/edit/delete accounts, grant/revoke the admin role |
| `members_import.php` | Admin-only: upload the club roster CSV; manually mark a Call Sign current |
| `migrate_db.php` | Run once on an *existing* install after any update, to bring the database up to date without losing data |
| `init_db.php` | One-time script that builds a brand-new database file |
| `schedule.php` | Calendar for the viewed month (&#9664;/&#9654; wedges page between months, "Today" snaps back), click a day → scheduling grid |
| `schedule_day.php` | 24-hour x 5-radio grid for one day; click a cell to reserve/release |
| `my_reservations.php` | List of the logged-in member's own reservations |
| `all_reservations.php` | List of every reservation by everyone |
| `radio_control.php` | Post-login landing page: one button per radio, opens the club's Node-RED control dashboard in a new tab after checking the current time slot isn't reserved by someone else |
| `pi_status.php` | Super-admin only (WD9GYM): live temperature/CPU/memory/disk gauges, Reboot/Shutdown with a Y/N confirmation |
| `check_reservation.php` | JSON endpoint radio_control.php calls to check for a reservation conflict |
| `logout.php` | Ends the session |
| `config.php` | Shared SQLite (PDO) connection and helper functions |
| `schema.sql` | Table definitions |
| `css/style.css` | Site styling |
| `images/*.png` | Radio photos |

## 1. Install PHP (if not already present)

Apache serves the pages, but you need PHP with the SQLite3/PDO extension:

```bash
sudo apt update
sudo apt install -y php libapache2-mod-php php-sqlite3
sudo systemctl restart apache2
```

## 2. Copy the site onto the Pi

Copy this whole folder to Apache's web root, e.g.:

```bash
sudo cp -r nsrc-flex /var/www/html/
sudo chown -R www-data:www-data /var/www/html/nsrc-flex
```

## 3. Create the database

```bash
cd /var/www/html/nsrc-flex
sudo -u www-data php init_db.php
```

This creates `db/nsrc_flex.db` with:
- `users` table (empty, ready for account creation)
- `radios` table pre-loaded with Skokie, Northfield, Northbrook,
  MunAV640, MunEndfed
- `reservations` table (empty, ready for the scheduling feature)

Make sure the `db/` folder is writable by Apache:

```bash
sudo chmod 775 /var/www/html/nsrc-flex/db
```

## 4. Visit the site

```
http://<your-pi-ip-address>/nsrc-flex/
```

## How the login/account logic works

- **Login** (`index.php`): looks up the Call Sign. If it doesn't exist,
  shows "Call Sign not found." If it exists but the password doesn't match
  the stored hash, shows "Incorrect password." On success, starts a PHP
  session and goes to `schedule.php`.
- **Calendar** (`schedule.php`): shows the viewed month with a wedge on
  each side to page to the previous/next month (`?year=&month=` in the
  URL). Today's date is highlighted only when actually viewing the current
  month. Clicking a day goes to `schedule_day.php?date=YYYY-MM-DD`.
- **Day grid** (`schedule_day.php`): a table with 24 hourly rows (shown in
  12-hour time, 12:00 AM–11:00 PM) and 5 columns — MunAV640, MunEndfed,
  Northbrook, Northfield, Skokie. Clicking an open cell reserves that
  radio for that hour under the logged-in Call Sign and saves it to the
  `reservations` table. The grid is populated from the database on every
  load, so everyone sees the same schedule. Clicking a cell that's already
  reserved *by you* releases it. Clicking a cell reserved by *someone
  else* leaves it alone and shows "That slot is already reserved by
  <Call Sign>." instead.
- **Passwords are never stored in plain text.** They're hashed with PHP's
  `password_hash()` (bcrypt) and checked with `password_verify()` — the
  database never contains anything the plain password can be recovered
  from, and there's no page that displays it.
- **Create Account** (`create_account.php`): requires Call Sign, Password,
  Verify Password (must match), and Email. Rejects duplicate Call Signs.
  Stores `created_at`/`updated_at` timestamps from the server clock —
  these are saved in the database but never shown in the UI.
- **Change Password** (`change_password.php`): asks for Call Sign, Old
  Password, New Password, Confirm New Password. Verifies the old password
  against the database, requires New = Confirm, then updates the stored
  hash and `updated_at`, and returns to the login page.

## Photos

The header photos are your own product photos of the Flex 6400/8400/8600
(`images/flex6400.png`, `flex8400.png`, `flex8600.png`).

## Look & feel

The site's visual identity borrows from the radios themselves: a clean
instrument-panel look, a spectrum-sweep gradient bar (like a panadapter
trace) under the header, and monospace type for call signs, dates, and
times so they read like equipment readouts. Each radio in the day
schedule grid gets its own accent color (cyan/violet/amber/green/rose)
so the grid is easy to scan at a glance.

Headings use Space Grotesk, body text uses Inter, and call
signs/times/dates use IBM Plex Mono, loaded from Google Fonts. The Pi
needs outbound internet access for those to load; if it doesn't, the
browser falls back to system fonts automatically and the site still
looks fine, just slightly less distinctive.

## Admin page

Visiting `admin.php` while logged in as Call Sign **WD9GYM** (any letter
case - `wd9gym`, `Wd9Gym`, etc. all match) shows every account with every
field editable in place, plus a Delete button per row. A link to this
page only appears on the calendar page, and only for that one Call Sign;
everyone else who tries to open `admin.php` directly is redirected back
to the calendar.

- **Update**: change Call Sign and/or Email inline; leave New Password
  blank to keep the current password, or type one to reset it. Renaming
  to a Call Sign already used by another account is rejected with an
  error instead of silently overwriting anything.
- **Delete**: asks for a Y/N confirmation, then removes the account.
  The admin cannot delete the account they're currently logged in as -
  that row's Delete button is disabled. Deleting an account does **not**
  delete that member's existing reservations - see below.

## Reservations outlive the account that made them

Deleting a member's account never deletes their radio reservations. Each
reservation stores a `call_sign_snapshot` at the moment it's booked, so
it keeps showing who reserved it (on the day grid, My Reservations, and
All Reservations) even after that account is gone. The database no
longer enforces a foreign key between reservations and users for this
reason - it's deliberate, not an oversight.

## My Reservations / All Reservations

Two buttons on the calendar page:
- **My Reservations** (`my_reservations.php`) - every slot booked under
  the logged-in Call Sign, soonest first.
- **All Reservations** (`all_reservations.php`) - every booking by
  everyone in the group, soonest first, with the Call Sign shown for
  each (via the snapshot if that account has since been deleted).

Both are available to any logged-in member, not just admin.

## Past time slots are locked

On the day scheduling grid, any hour that has already started is grayed
out and can't be booked, released, or changed by a regular click - it
silently does nothing rather than showing an error, since there's
nothing to say about clicking something that can no longer be changed.
The admin (WD9GYM) is the one exception: clicking a filled slot, past or
present, always clears it - useful for correcting a mistaken booking
after the fact.

This depends on the server's timezone being set correctly - see below.

## Timezone

`config.php` sets the timezone explicitly to `America/Chicago`. Without
this, PHP defaults to UTC regardless of the Pi's own system clock, which
made "today" flip over 5-6 hours before local midnight (depending on
daylight saving). If the club ever isn't in Central time, change this
one line.

To change which Call Sign has admin access, edit `ADMIN_CALL_SIGN` in
`config.php`.

## Updating an existing install (adding last_login)

This version adds a `last_login` timestamp, stamped every time someone
logs in successfully (in addition to the `created_at`/`updated_at`
timestamps that already existed). If you're updating a site that's
already running with real accounts in it, run the migration once so you
don't lose them:

```bash
cd /var/www/html          # wherever the site lives
sudo -u www-data php migrate_db.php
```

This only adds the new column; it does not touch existing rows.

**If you'd rather just start over with an empty database** (fine for a
brand-new install, or if you don't mind re-creating everyone's accounts),
delete the old database file and re-initialize instead:

```bash
cd /var/www/html
sudo rm -f db/nsrc_flex.db
sudo -u www-data php init_db.php
```

## Call Sign case handling

Call Signs are normalized to uppercase and trimmed of stray spaces
everywhere they're entered (login, account creation, change password,
admin edits) - so `w9abc`, `W9ABC`, and ` w9abc ` are all treated as the
same account. This also fixes the one gap in the original duplicate
check: previously two accounts could exist that differed only in
letter case.

## Radio activation

`radio_control.php` shows one button per radio. Clicking it:

1. Checks whether the *current hour* is already reserved by someone else
   on that radio (via `check_reservation.php`). If so, nothing opens -
   a message appears explaining who has it reserved.
2. Otherwise, opens the club's Node-RED control dashboard
   (`NODE_RED_URL` in `config.php`) in a new browser tab, where the
   actual radio control happens.

The site itself doesn't control the radios directly - it only
gatekeeps against double-booking and hands off to Node-RED, which
already does the real work reliably.

**`NODE_RED_URL`** must be the Pi's own LAN address (e.g.
`http://10.0.0.209:1880/dashboard/radio-activation`), not
`127.0.0.1` - in a member's browser, `127.0.0.1` means their own
device, not the Pi, so the tab would open to nothing. Update this one
constant if the Pi's address or the dashboard path ever changes; a
dynamic DNS name can go here later.

## Pi Status (super admin only)

A "Pi Status" button appears on the calendar page, but only for WD9GYM
specifically - not any delegated admin. It shows four live gauges
(Temperature, CPU Load, Memory Usage, Disk Usage) and Reboot/Shutdown
buttons behind the same Y/N confirmation popup used elsewhere on the
site.

This was converted from the "V1 Raspberry Control" Node-RED flow you
provided - the temperature, CPU, and disk commands are the exact same
ones that flow used:

- Temperature: `vcgencmd measure_temp`
- CPU Load: `top -d 0.5 -b -n2 | grep "Cpu(s)" | tail -n 1 | awk '{print $2 + $4}'`
- Disk Usage: `df -h /`

Memory Usage wasn't in that flow, so it uses the standard equivalent
(`free`). Reboot and Shutdown run `sudo reboot` / `sudo shutdown -h now`
directly, same as the flow's exec nodes did.

**Required one-time setup on the Pi**: unlike everything else on this
site, Reboot and Shutdown need root privileges, and Apache runs as
`www-data`, which doesn't have that by default. Grant it *only* for
these two exact commands (not general sudo access):

```bash
sudo visudo -f /etc/sudoers.d/nsrc-flex-pi-status
```

Add this line (adjust the reboot/shutdown paths if `which reboot` /
`which shutdown` point somewhere else on your system):

```
www-data ALL=(root) NOPASSWD: /sbin/reboot, /sbin/shutdown -h now
```

Without this, clicking Reboot or Shutdown will silently do nothing
(the command will be rejected by `sudo` since it can't prompt for a
password over a web request).

I tested the gauges and the full Y/N confirm-and-submit flow end to
end (the Y path correctly submits and shows the confirmation message;
the N path correctly cancels with no action taken). I could not test
the actual `sudo reboot`/`sudo shutdown` execution or `vcgencmd`
against real Raspberry Pi hardware from this environment - worth
confirming those two buttons actually work once the sudoers file above
is in place on the real Pi.
