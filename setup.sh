#!/bin/bash
# Setup script for KRA Nakuru Tax System on XAMPP
# Run with: sudo bash setup.sh

set -e

PROJECT_DIR="/home/mol/Downloads/kra_nakuru_tax_system"
HTDOCS="/opt/lampp/htdocs/kra_nakuru"

echo "=== KRA Nakuru Tax System Setup ==="

# 1. Start XAMPP
echo "[1/4] Starting XAMPP..."
/opt/lampp/lampp start 2>&1
echo "XAMPP started."

# 2. Copy application files to XAMPP htdocs
echo "[2/4] Copying application files to $HTDOCS ..."
rm -rf "$HTDOCS"
mkdir -p "$HTDOCS"
cp -r "$PROJECT_DIR"/* "$HTDOCS"/
echo "Files copied."

# 3. Import the database
echo "[3/4] Importing database..."
/opt/lampp/bin/mysql < "$PROJECT_DIR/database.sql"
echo "Database imported successfully."

# 4. Verify
echo "[4/4] Verifying setup..."
/opt/lampp/bin/mysql -e "USE kra_nakuru_tax; SELECT COUNT(*) as user_count FROM users;" 2>&1
echo ""
echo "=== Setup Complete! ==="
echo "Open your browser and go to: http://localhost/kra_nakuru"
echo "Login with: admin / Admin@123"
