#!/usr/bin/env bash
# Run this on the Raspberry Pi (as the user that should own the service, with sudo access).
# Sets up barcode_scanner to run under PM2 and restart automatically on boot.
set -euo pipefail

APP_BIN="./barcode_scanner"
APP_NAME="barcode-scanner"

# Step 1: Ensure Node.js is installed (needed for PM2)
if ! command -v node >/dev/null 2>&1; then
    echo "Installing Node.js LTS..."
    curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
    sudo apt-get install -y nodejs
fi

# Step 2: Ensure PM2 is installed globally
if ! command -v pm2 >/dev/null 2>&1; then
    echo "Installing PM2 globally..."
    sudo npm install -g pm2
fi

# Step 3: Make sure the scanner binary exists and is executable
if [ ! -x "$APP_BIN" ]; then
    echo "Error: $APP_BIN not found or not executable in $(pwd)."
    echo "Build it first, e.g. from your dev machine:"
    echo "  GOOS=linux GOARCH=arm64 go build -o barcode_scanner barcode_scanner.go   # 64-bit Pi OS"
    echo "  GOOS=linux GOARCH=arm GOARM=7 go build -o barcode_scanner barcode_scanner.go   # 32-bit Pi OS"
    exit 1
fi

# Step 4: Make sure this user can access the serial port (/dev/ttyUSB*, /dev/ttyACM*)
if ! groups "$USER" | grep -q '\bdialout\b'; then
    echo "Adding $USER to the dialout group (required to access the serial port)..."
    sudo usermod -aG dialout "$USER"
    echo "NOTE: log out and back in (or reboot) before the scanner will be accessible."
fi

# Step 5: Start the app under PM2
pm2 start "$APP_BIN" --name "$APP_NAME"
pm2 save

# Step 6: Register PM2 with systemd so saved processes come back on boot
STARTUP_CMD=$(pm2 startup systemd -u "$USER" --hp "$HOME" | tail -1)
echo "Running: $STARTUP_CMD"
eval "$STARTUP_CMD"

echo "Done. '$APP_NAME' is running under PM2 and will restart on boot."
echo "Check status with: pm2 status"
echo "Check logs with:    pm2 logs $APP_NAME"
