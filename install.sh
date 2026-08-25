#!/bin/bash
## ---- install.sh ----- ##
## Version: 1.6
## Updated: 2026-08-25
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

# Spinner that runs indefinitely - unlike ProgressBar (which guesses a
# fixed duration and finishes even if the real command is still
# running), this keeps animating for as long as the actual command
# takes, so the screen never goes static while something is still
# working in the background. Call `spin_start` right before the real
# command, then `spin_stop` right after it finishes.
function spin_start {
  ( local chars="/-\|"
    local i=0
    while true; do
      i=$(( (i+1) % 4 ))
      printf "\r%s Working..." "${chars:$i:1}"
      sleep 0.3
    done
  ) &
  SPIN_PID=$!
  disown "$SPIN_PID" 2>/dev/null
}

function spin_stop {
  if [ -n "$SPIN_PID" ]; then
    kill "$SPIN_PID" 2>/dev/null
    wait "$SPIN_PID" 2>/dev/null
  fi
  printf "\r%-60s\r" " "
  SPIN_PID=""
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
    if sudo -n DEBIAN_FRONTEND=noninteractive apt-get update -qq > /dev/null 2>/tmp/apt_update_err; then
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
  if sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" > /tmp/apt_install_err 2>&1; then
    return 0
  fi

  echo "Install of '$*' failed, rebuilding apt package index and retrying..."
  sudo rm -rf /var/lib/apt/lists/*
  sudo apt-get clean
  apt_update_or_die

  if sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" > /tmp/apt_install_err 2>&1; then
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
echo "Scheduler Installer - Version 1.6 (Updated 2026-08-25)"
printf "Welcome to the Scheduler Installer.\nPlease hit enter to continue. "
read
echo "Are you wanting to update Node-Red?"
echo -n "Only choose no if you have installed Node-red already on this machine. Most people will choose Yes."
read -p "(Y/n) " flag_update

# Ask for the sudo password once, up front, then keep the credential
# cache alive in the background for the rest of the script. Without
# this, sudo's timestamp can expire during a long unattended step
# (e.g. the multi-minute progress-bar wait during full-upgrade) and a
# later "sudo ..." call whose output is redirected to /dev/null will
# silently re-prompt for the password - looking like the script has
# hung until you notice and type it in blind.
echo "Requesting sudo access up front so long steps below don't silently re-prompt..."
until sudo -v; do
  echo "That password didn't work - please try again."
done
echo "Sudo access confirmed."
( while true; do sudo -n true; sleep 60; kill -0 "$$" 2>/dev/null || exit; done ) 2>/dev/null &
SUDO_KEEPALIVE_PID=$!
trap 'kill "$SUDO_KEEPALIVE_PID" 2>/dev/null' EXIT

# Prevent apt from ever blocking on a hidden interactive prompt
# (service-restart dialogs, config-file-conflict dialogs, etc.) -
# these show up on stdin/stdout even when the surrounding command's
# output is redirected, so they can look identical to a hang.
export DEBIAN_FRONTEND=noninteractive

wait_for_network
## ---- Update RPI / Install Node-Red ---- ##
if  [[ $flag_update != 'n' ]] && [[ $flag_update != 'N' ]]; then
echo "Updating and Upgrading your Pi to newest standards"
spin_start
apt_update_or_die
sudo -n DEBIAN_FRONTEND=noninteractive apt-get full-upgrade -qq -y > /dev/null && sudo apt-get clean > /dev/null
spin_stop
# -- Install Node-Red -- #

# A recent version of the official Node-RED installer, when it finds
# no existing settings.js, auto-launches an interactive "Settings File
# initialisation" wizard after the install itself finishes. Our
# heredoc below only answers the two original y/y install-confirmation
# prompts - it knows nothing about this newer wizard, so without a
# settings.js already in place, the installer hangs waiting on the
# wizard's first question. Pre-creating a minimal settings.js here
# means Node-RED finds one already exists and skips the wizard
# entirely, so the heredoc's two answers are all that's ever needed.
mkdir -p "$HOME/.node-red"
if [ ! -f "$HOME/.node-red/settings.js" ]; then
  echo "Pre-creating a minimal Node-RED settings.js to skip the interactive setup wizard..."
  cat > "$HOME/.node-red/settings.js" << 'SETTINGS_EOF'
module.exports = {
    uiPort: process.env.PORT || 1880,
    flowFile: 'flows.json',
    userDir: process.env.HOME + '/.node-red/',
    functionGlobalContext: {},
};
SETTINGS_EOF
fi

bash <(curl -sL https://raw.githubusercontent.com/node-red/linux-installers/master/deb/update-nodejs-and-nodered) <<!
y
y
!
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
spin_start
apt_install_or_die apache2 mariadb-server php php-mysql php-sqlite3 php-cli libapache2-mod-php sqlite3
spin_stop
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
echo "Installing Git and unzip (if needed)..."
apt_install_or_die git unzip
echo "Git/unzip install  Y"

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

# Download the site archive from Dropbox (dl=1 forces a direct file
# download instead of Dropbox's HTML preview page).
DROPBOX_ZIP_URL="https://www.dropbox.com/scl/fi/qwrwi589pj7si9g2etboi/nsrc-flex-scheduler.zip?rlkey=m73one1x1dc8eiovt63mg28pu&st=s4s6f7u5&dl=1"

echo "Downloading site archive from Dropbox..."
curl -fL -o nsrc-scheduler.zip "$DROPBOX_ZIP_URL"

if [ ! -s nsrc-scheduler.zip ]; then
  echo "ERROR: nsrc-scheduler.zip download failed or produced an empty file."
  exit 1
fi

# Validate we actually got a real zip archive before extracting - if
# Dropbox served an HTML/error page instead (bad link, expired share,
# rate limit, etc.), fail loudly here rather than let extraction
# produce a confusing error.
if ! unzip -tq nsrc-scheduler.zip > /dev/null 2>&1; then
  echo "ERROR: downloaded file is not a valid zip archive. First 300 bytes:"
  head -c 300 nsrc-scheduler.zip
  echo ""
  echo "Check the Dropbox share link (must end in dl=1 and be set to 'Anyone with the link') and re-run."
  exit 1
fi

sudo unzip -o nsrc-scheduler.zip -d /var/www/html
sudo rm -f nsrc-scheduler.zip

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
