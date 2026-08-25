#!/bin/bash
## ---- Functions ----- ##
#Create ProgressBar function
function ProgressBar {
# Process data
	let _progress=(${1}*100/${2}*100)/100
	let _done=(${_progress}*4)/10
	let _left=40-$_done
# Build progressbar string lengths
	_done=$(printf "%${_done}s")
	_left=$(printf "%${_left}s")
# Output example:
# Progress : [########################################] 100%
    printf "\rProgress : [${_done// /#}${_left// /-}] ${_progress}%%"
}

# On a freshly-imaged Pi, Wi-Fi/DNS can take a few extra seconds to be
# fully ready even though the network interface appears up. Running
# apt-get update too early fails silently under -qq, leaving every
# later "apt-get install" unable to find any packages. Wait for real
# connectivity before touching apt at all.
function wait_for_network {
  echo "Checking network connectivity..."
  for i in $(seq 1 30); do
    if getent hosts deb.debian.org > /dev/null 2>&1 || getent hosts archive.raspberrypi.org > /dev/null 2>&1; then
      echo "Network is up."
      return 0
    fi
    sleep 2
  done
  echo "ERROR: no network/DNS after 60 seconds. Check your Wi-Fi/Ethernet and re-run this script."
  exit 1
}

# apt-get update can fail transiently (network still settling, mirror
# hiccup). Retry a few times and fail loudly - rather than silently -
# if it never succeeds, since every install step downstream depends on
# a valid package index.
function apt_update_or_die {
  for i in 1 2 3 4 5; do
    if sudo apt-get update -qq > /dev/null 2>/tmp/apt_update_err; then
      return 0
    fi
    echo "apt-get update failed (attempt $i/5), retrying in 5s..."
    sleep 5
  done
  echo "ERROR: apt-get update failed after 5 attempts. Last error:"
  cat /tmp/apt_update_err
  exit 1
}

# apt-get install can also fail (missing package index, network
# hiccup, etc). Check the exit code explicitly instead of letting a
# failure pass silently into later steps that assume it worked. If it
# fails, try one full apt index rebuild (wipe /var/lib/apt/lists and
# re-update) before giving up - this fixed a real-world case where
# apt-get update reported success but the index was still stale/broken.
function apt_install_or_die {
  if sudo apt-get install -y -qq "$@" > /tmp/apt_install_err 2>&1; then
    return 0
  fi

  echo "Install of '$*' failed, rebuilding apt package index and retrying..."
  sudo rm -rf /var/lib/apt/lists/*
  sudo apt-get clean
  apt_update_or_die

  if sudo apt-get install -y -qq "$@" > /tmp/apt_install_err 2>&1; then
    return 0
  fi

  echo "ERROR: failed to install: $*"
  cat /tmp/apt_install_err
  exit 1
}

# Variables
_start=1
_end=100

# GitHub / project variables
GITHUB_USER="mboroff"
REPO_NAME="src-flx-scheduler-node-red-project"
HTTPS_URL="https://github.com/${GITHUB_USER}/${REPO_NAME}.git"
PROJECT_NAME="nsrc-flex-scheduler"
NODE_RED_DIR="$HOME/.node-red"
PROJECTS_DIR="$NODE_RED_DIR/projects"
PROJECT_DIR="$PROJECTS_DIR/$PROJECT_NAME"

clear
## ---- Initial Questioning ---- ##
printf "Welcome to the Scheduler Installer.\nPlease hit enter to continue. "
read
echo "Are you wanting to update Node-Red?"
echo -n "Only choose no if you have installed Node-red already on this machine. Most people will choose Yes."
read -p "(Y/n) " flag_update

wait_for_network
## ---- Update RPI / Install Node-Red ---- ##
if  [[ $flag_update != 'n' ]] && [[ $flag_update != 'N' ]]; then
echo "Updating and Upgrading your Pi to newest standards"
for number in $(seq ${_start} ${_end})
do
	sleep 2
	ProgressBar ${number} ${_end}
done &
bgid=$!
apt_update_or_die
sudo apt-get full-upgrade -qq -y > /dev/null && sudo apt-get clean > /dev/null
kill $bgid
wait
ProgressBar ${_end}  ${_end}
# -- Install Node-Red -- #
bash <(curl -sL https://raw.githubusercontent.com/node-red/linux-installers/master/deb/update-nodejs-and-nodered) <<!
y
y
!
wait
clear
echo "**Scheduler Install Status**"
echo "Updating and Upgrading your Pi to newest standards  Y"
echo "Install and Update NodeRed  Y"
fi
# -- Enable Node-Red for auto startup -- #
sudo systemctl enable --now nodered.service
echo "Node-Red enabled for auto startup  Y"
# -- Install Apache, SQL (MariaDB), and PHP -- #
echo "Installing Apache, MariaDB, and PHP"
for number in $(seq ${_start} ${_end})
do
	sleep 1
	ProgressBar ${number} ${_end}
done &
bgid=$!
apt_install_or_die apache2 mariadb-server php php-mysql php-sqlite3 php-cli libapache2-mod-php sqlite3
kill $bgid
wait
ProgressBar ${_end} ${_end}
echo ""
echo "Install Apache, SQL, & PHP  Y"

# Node-RED's reservation-status check (in the scheduler's flows.json)
# shells out to the sqlite3 CLI directly rather than a Node-RED
# database node, so confirm it actually landed on PATH before moving on.
if ! command -v sqlite3 > /dev/null 2>&1; then
  echo "ERROR: sqlite3 command-line tool not found after install. The scheduler flows depend on it."
  exit 1
fi
echo "sqlite3 CLI available  Y"
# -- Enable Apache and MariaDB for auto startup -- #
sudo systemctl enable --now apache2.service
sudo systemctl enable --now mariadb.service
echo "Apache and MariaDB enabled for auto startup  Y"
# ---- Placeholder: database setup / permissions for reboot & shutdown commands ---- #
# TODO: Add database/schema commands here.
# TODO: Add commands granting the necessary user/authority to run reboot and shutdown commands.

## ---- Clone Scheduler project from GitHub (runs last, after all other installs) ---- ##
echo ""
echo "Installing Git (if needed)..."
apt_install_or_die git
echo "Git install  Y"

echo ""
echo "Now setting up the Scheduler Node-RED project from GitHub."
mkdir -p "$PROJECTS_DIR"

# Repo is public, so plain anonymous HTTPS clone works - no auth needed.
if [ -d "$PROJECT_DIR/.git" ]; then
  echo "Project directory already exists at $PROJECT_DIR"
  echo "Pulling latest changes instead of cloning..."
  cd "$PROJECT_DIR"
  git remote set-url origin "$HTTPS_URL"
  git pull origin main
else
  echo "Cloning project into $PROJECT_DIR ..."
  git clone "$HTTPS_URL" "$PROJECT_DIR"
  cd "$PROJECT_DIR"
fi

echo "Installing project dependencies (npm install)..."
if [ -f "package.json" ]; then
  npm install
else
  echo "No package.json found, skipping npm install."
fi

# Node-RED's Projects feature requires interactive first-run setup
# (the tour/wizard) to actually enable it in settings.js. Rather than
# fight that non-interactively, just copy the repo's flow files
# straight into ~/.node-red, which Node-RED loads by default with no
# extra config needed.
echo "Stopping Node-RED before updating flow files..."
sudo systemctl stop nodered.service

echo "Copying flow files into $NODE_RED_DIR ..."
for f in flows.json flows_cred.json; do
  if [ -f "$PROJECT_DIR/$f" ]; then
    cp "$PROJECT_DIR/$f" "$NODE_RED_DIR/$f"
    echo "  Copied $f"
  else
    echo "  $f not found in project, skipping"
  fi
done

# Node-RED only looks for installed nodes inside its own directory
# (~/.node-red), not inside the cloned project folder. So any custom/
# contrib nodes the flows depend on (listed in the project's
# package.json) need to be installed into ~/.node-red directly.
if [ -f "$PROJECT_DIR/package.json" ]; then
  echo "Checking for additional Node-RED nodes required by the project..."
  DEPS="$(node -e "
    const proj = require('$PROJECT_DIR/package.json');
    const deps = Object.assign({}, proj.dependencies, proj.devDependencies);
    console.log(Object.keys(deps).join(' '));
  " 2>/dev/null)"
  if [ -n "$DEPS" ]; then
    echo "  Installing into $NODE_RED_DIR: $DEPS"
    cd "$NODE_RED_DIR"
    npm install $DEPS
  else
    echo "  No additional node dependencies listed in project package.json"
  fi
fi

echo "Restarting Node-RED..."
sudo systemctl restart nodered.service
echo "Scheduler project setup  Y"

HOSTIP=`hostname -I | cut -d ' ' -f 1`
    if [ "$HOSTIP" = "" ]; then
        HOSTIP="127.0.0.1"
    fi

## ---- Deploy web front-end and grant reboot/shutdown permissions ---- ##
echo ""
echo "Deploying the Scheduler web site..."

cd /var/www/html || { echo "ERROR: could not cd into /var/www/html (does it exist? is Apache installed?)"; exit 1; }
echo "Now in $(pwd) - downloading site archive here."

# Remove the default Apache landing page
if [ -f index.html ]; then
  sudo rm -f index.html
fi

# Download the site archive by cloning the nsrc-flex-scheduler repo
# directly (public repo, anonymous HTTPS, no auth needed). This avoids
# the fragility of guessing GitHub's raw-file URL format.
WEBSITE_REPO_NAME="nsrc-flex-scheduler"
WEBSITE_HTTPS_URL="https://github.com/${GITHUB_USER}/${WEBSITE_REPO_NAME}.git"
WEBSITE_CLONE_DIR="/tmp/${WEBSITE_REPO_NAME}"

rm -rf "$WEBSITE_CLONE_DIR"

# Repo is public, so plain anonymous HTTPS clone works - no auth needed.
git clone "$WEBSITE_HTTPS_URL" "$WEBSITE_CLONE_DIR"

if [ ! -f "$WEBSITE_CLONE_DIR/nsrc-scheduler.tar" ]; then
  echo "ERROR: nsrc-scheduler.tar not found in the cloned $WEBSITE_REPO_NAME repo."
  echo "Contents of the repo:"
  ls -la "$WEBSITE_CLONE_DIR"
  exit 1
fi

sudo cp "$WEBSITE_CLONE_DIR/nsrc-scheduler.tar" ./nsrc-scheduler.tar
rm -rf "$WEBSITE_CLONE_DIR"

# Validate we actually got a tar archive before extracting - if GitHub
# served an HTML/error page instead (redirect issue, wrong path, etc.),
# fail loudly here rather than let extraction produce a confusing error.
# Uses tar's own list/test mode rather than the `file` command, since
# `file` isn't guaranteed to be installed on a fresh Pi image.
if ! tar -tf nsrc-scheduler.tar > /dev/null 2>&1; then
  echo "ERROR: downloaded file is not a valid tar archive. First 300 bytes:"
  head -c 300 nsrc-scheduler.tar
  echo ""
  echo "Check the raw URL for nsrc-scheduler.tar in the nsrc-flex-scheduler repo and re-run."
  exit 1
fi

sudo tar -xvf nsrc-scheduler.tar
sudo rm -f nsrc-scheduler.tar

# The tar extracts its files directly into the current directory
# (paths like ./index.php), not into a wrapping "nsrc-flex" subfolder,
# so operate on /var/www/html itself.
sudo chown -R www-data:www-data /var/www/html
sudo -u www-data php /var/www/html/init_db.php
sudo chmod 775 /var/www/html/db

# Node-RED (running as the current user, not www-data) reads this same
# database directly via the sqlite3 CLI for the reservation-status
# check in flows.json. Add the current user to the www-data group so
# it can read the db file/directory without loosening permissions
# further (db dir is 775, db file is 664 - group-readable is enough).
echo "Granting $(whoami) read access to the reservations database..."
sudo usermod -a -G www-data "$(whoami)"

# init_db.php can succeed without the pdo_sqlite extension actually
# being enabled, but the site's live queries need it at runtime.
# Explicitly ensure it's enabled and restart Apache to pick it up.
echo "Ensuring pdo_sqlite PHP extension is enabled..."
apt_install_or_die php-sqlite3
sudo phpenmod pdo_sqlite
sudo systemctl restart apache2

echo "Web site deployed  Y"

# Grant www-data passwordless permission to reboot/shutdown the Pi
echo "Configuring reboot/shutdown permissions for the web server..."
echo "www-data ALL=(root) NOPASSWD: /sbin/reboot, /sbin/shutdown -h" | sudo tee /etc/sudoers.d/nsrc-flex-pi-status > /dev/null
sudo chmod 440 /etc/sudoers.d/nsrc-flex-pi-status

# Validate the sudoers file before trusting it - a broken sudoers.d file
# can break sudo system-wide, so check it and roll back if invalid.
if ! sudo visudo -c -f /etc/sudoers.d/nsrc-flex-pi-status > /dev/null 2>&1; then
  echo "WARNING: sudoers file for nsrc-flex-pi-status failed validation. Removing it to avoid breaking sudo."
  sudo rm -f /etc/sudoers.d/nsrc-flex-pi-status
else
  echo "Reboot/shutdown permissions configured  Y"
fi

echo ""
echo "Setup has completed. Head to http://$HOSTIP:1880/ui to access Node-Red."
echo "Head to http://$HOSTIP/ to access the Apache web server."
echo ""
echo "IMPORTANT: this session was just added to the www-data group so"
echo "Node-RED can read the reservations database, but that change does"
echo "NOT apply to any shell/session already open - including this one."
echo "You must log out and back in (or reboot) before the radio-status"
echo "checks in Node-RED will work. A reboot is the safest option since"
echo "it also confirms Node-RED, Apache, and MariaDB all come back up"
echo "correctly on their own."
echo ""
read -p "Reboot now? (Y/n) " flag_reboot
if [[ $flag_reboot != 'n' ]] && [[ $flag_reboot != 'N' ]]; then
  echo "Rebooting..."
  sudo reboot
else
  echo "Skipping reboot - remember to log out/in or reboot manually before testing."
fi
