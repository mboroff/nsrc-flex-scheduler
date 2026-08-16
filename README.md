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

## Install Script

Below is the full install of the NSRC Flex-Cadre Radio Scheduling site from scratch. Once you have imaged a Raspberry Pi, issue the following command at the command prompt to start the install. This script takes 15–20 mins to run on a Pi4.

```bash
bash <(curl -sL https://raw.githubusercontent.com/mboroff/nsrc-flex-scheduler/main/install.sh)
```

This installs and configures Node-RED, Apache, MariaDB, PHP (with the
`pdo_sqlite` extension), clones the scheduler flows and this web site
from GitHub, initializes the database, and sets up the sudoers rule
needed for the Pi Status Reboot/Shutdown buttons. See the sections
below for what to do manually if you're setting things up step by step
instead, or updating an existing install.

### How the web site files get onto the Pi

The Node-RED flows (`src-flx-scheduler-node-red-project` repo) and this
PHP web site (`nsrc-flex-scheduler` repo) are two separate GitHub
repositories. The script handles them differently:

- **Node-RED flows**: cloned directly with `git clone` into
  `~/.node-red/projects/nsrc-flex-scheduler`, since Node-RED just needs
  the flow files (`flows.json`, `flows_cred.json`) copied into
  `~/.node-red` and any node dependencies from the project's
  `package.json` installed there.
- **Web site**: rather than cloning the web repo's working tree
  directly into `/var/www/html` (which would also drop a `.git` folder
  and any non-site files into Apache's web root), the script clones the
  repo to a scratch location under `/tmp`, expects to find a
  **`nsrc-scheduler.tar`** archive inside it, and extracts *that* into
  `/var/www/html`. This keeps the deployed site to exactly the files
  meant to be served, with no version-control metadata alongside it.

  The steps, in order:
  1. `cd /var/www/html` and remove the default Apache landing page
     (`index.html`) if present.
  2. `git clone` the web site repo into a `/tmp` scratch folder (public
     repo, plain anonymous HTTPS - no token/auth needed).
  3. Confirm `nsrc-scheduler.tar` exists inside the cloned folder; if
     it's missing, the script lists the repo contents and exits with an
     error rather than continuing with nothing to deploy.
  4. Copy `nsrc-scheduler.tar` into `/var/www/html`, then delete the
     `/tmp` scratch clone.
  5. **Validate before extracting**: run `tar -tf` on the archive first.
     If GitHub ever served an HTML error/redirect page instead of the
     real file (wrong path, repo renamed, etc.), this catches it
     immediately - printing the first 300 bytes of whatever was
     downloaded - instead of letting `tar -xvf` fail confusingly or
     silently extract garbage.
  6. Extract the tar (`sudo tar -xvf nsrc-scheduler.tar`) directly into
     `/var/www/html`, then remove the tar file. The archive's paths are
     relative (`./index.php`, etc.) with no wrapping folder, so the
     files land straight in the web root.
  7. `chown -R www-data:www-data /var/www/html` so Apache owns
     everything just deployed.
  8. Run `init_db.php` as `www-data` to create `db/nsrc_flex.db`, then
     `chmod 775 db/` so the web server can write to it.
  9. Explicitly `apt-get install php-sqlite3` and `phpenmod pdo_sqlite`
     and restart Apache - `init_db.php` can succeed even if the
     extension isn't actually enabled for live requests, so this step
     makes sure the running site has it.

  **When updating this repo**, keep `nsrc-scheduler.tar` in sync with
  the actual site files (rebuild/re-commit it whenever `index.php`,
  `config.php`, etc. change) - the installer only ever looks at the tar,
  not the individual files sitting next to it in the repo.

- **Reboot/Shutdown permissions**: after the site is deployed, the
  script also writes the `www-data ALL=(root) NOPASSWD: /sbin/reboot,
  /sbin/shutdown -h` sudoers rule needed by `pi_status.php` (see the
  "Pi Status" section below), validating it with `visudo -c` and
  removing it again if it fails validation - a broken sudoers.d file
  could otherwise break `sudo` system-wide.

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
  For every Call Sign except the super-admin (**WD9GYM**, from
  `ADMIN_CALL_SIGN` in `config.php`), the Call Sign must also appear on
  the club membership roster (`members` table) with current dues, or
  account creation is rejected. **WD9GYM is exempt from the membership
  check** - an explicit exemption lets that one Call Sign create its
  account without needing to appear on the roster or have current dues,
  so the super-admin is never locked out. Every other Call Sign still
  goes through the existing membership-list check unchanged, and the
  rest of the account-creation logic (duplicate check, email validation,
  password hashing) is identical either way. Stores
  `created_at`/`updated_at` timestamps from the server clock — these are
  saved in the database but never shown in the UI.
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
(Temperature, CPU Load, Memory Usage, Disk Usage), a **Refresh** button
to re-check them on demand, and Reboot/Shutdown buttons behind the same
Y/N confirmation popup used elsewhere on the site.

The gauges only run their commands when the page loads (including a
Refresh click) - there's no auto-polling on this page.

- **Temperature** is read directly from
  `/sys/class/thermal/thermal_zone0/temp` (the kernel's own thermal
  sensor file) rather than shelling out to `vcgencmd` - this avoids
  depending on `vcgencmd` being on Apache's PATH or `www-data` having
  permission to run it. Shown in **&deg;F** (0-200 range), converted
  from the millidegree-Celsius value the kernel reports.
- **CPU Load**: `top -d 0.5 -b -n2 | awk '/Cpu\(s\)/ { value = $2 + $4 } END { print value }'`
  (same idea as the "V1 Raspberry Control" Node-RED flow this was
  converted from, just written as a single awk pass).
- **Memory Usage**: `free | awk '/Mem:/ {print ($3 / $2) * 100.0}'`
  (not in the original flow, which only covered temp/CPU/disk - this is
  the standard equivalent).
- **Disk Usage**: `df -P / | awk 'END {gsub(/%/, "", $5); print $5}'`.

If a gauge command fails, the page shows "N/A" rather than a wrong
number.

### Reboot and Shutdown

These run through a small helper (`run_pi_power_action()`) rather than
building a shell string from anything the browser sends - the command
and its arguments are hardcoded PHP arrays, and the actual system call
checks the real exit code from `sudo` rather than assuming success.
Every Reboot/Shutdown form submission is also checked against a CSRF
token stored in the session, so another site can't trigger one of
these by tricking an already-logged-in admin's browser into submitting
the form.

- **Reboot** runs `sudo -n -- /sbin/reboot`.
- **Shutdown** runs `sudo -n -- /sbin/shutdown -h` (deliberately
  *without* `now`) - `shutdown -h` with no time argument defaults to a
  1-minute delay, which gives the command time to actually return its
  output (and this page time to show it) before the Pi goes down. With
  `now`, the system can start shutting down before the HTTP response
  finishes, which looked like the command "did nothing" even though it
  had worked.
- `sudo -n` (non-interactive) makes a missing/misconfigured sudoers
  rule fail immediately with a clear error instead of hanging the
  request while `sudo` waits for a password that can never come over a
  web request.

### Required one-time setup on the Pi

Reboot/Shutdown need root, and Apache's `www-data` user doesn't have
that by default. Grant it *only* for these two exact commands (not
general sudo access):

```bash
sudo visudo -f /etc/sudoers.d/nsrc-flex-pi-status
```

Add this line - first confirm with `which reboot` and `which shutdown`
that the paths match your system (some Debian-based systems put these
in `/usr/sbin` instead of `/sbin`; adjust below if so). Note **no
`now`** on the shutdown entry, matching what the script actually runs:

```
www-data ALL=(root) NOPASSWD: /sbin/reboot, /sbin/shutdown -h
```

The temperature gauge needs no special permissions - reading
`/sys/class/thermal/thermal_zone0/temp` is world-readable on standard
Raspberry Pi OS, which is exactly why switching to it instead of
`vcgencmd` (which needed the `www-data` user added to the `video`
group) was worth doing.

I verified the page end to end: the CSRF check correctly rejects a
submission without a valid token, the Y/N confirm-and-submit flow
works both ways (Y submits, N cancels with no action taken), the
Refresh button re-runs the gauges, the Fahrenheit conversion and 0-200
range are wired up, and the shutdown command no longer includes `now`.
I could not test actual `sudo reboot`/`sudo shutdown` execution or the
thermal-zone read against real Raspberry Pi hardware from this
environment - worth confirming those against the real Pi once the
sudoers file above is in place.
