#!/bin/bash

# Usage:
# 1. Make the script executable:
#      chmod +x website_manager.sh
# 2. Run one of the following commands:
#      ./website_manager.sh deploy
#      ./website_manager.sh analyze
#      ./website_manager.sh full-backup
#      ./website_manager.sh incremental-backup

# Set variables
WEB_ROOT="/var/www/html/prestashop"
BACKUP_DIR="$HOME/prestashop_backups"
INCREMENTAL_FILE="$BACKUP_DIR/last_backup.snar"
S3_BUCKET="s3://your-bucket/prestashop-backups"

# 1. Deploy PrestaShop and dependencies (Ubuntu example)
deploy_prestashop() {
    sudo apt update
    sudo apt install -y apache2 mysql-server php php-mysql php-xml php-gd php-curl php-zip wget unzip
    sudo systemctl enable apache2 mysql
    sudo systemctl start apache2 mysql

    # Download and extract PrestaShop
    wget https://download.prestashop.com/download/releases/prestashop_1.7.8.9.zip -O /tmp/prestashop.zip
    sudo unzip /tmp/prestashop.zip -d $WEB_ROOT
    sudo chown -R www-data:www-data $WEB_ROOT
    echo "PrestaShop files deployed to $WEB_ROOT"
}

# 2. Analytical Module
analyze_website() {
    echo "Total files: $(find $WEB_ROOT -type f | wc -l)"
    echo "Total directories: $(find $WEB_ROOT -type d | wc -l)"
    echo "File type distribution (count and total size):"
    find $WEB_ROOT -type f | sed 's/.*\.//' | sort | uniq | while read ext; do
        count=$(find $WEB_ROOT -type f -name "*.$ext" | wc -l)
        size=$(find $WEB_ROOT -type f -name "*.$ext" -exec du -cb {} + | grep total$ | awk '{print $1}')
        echo "$ext: $count files, $size bytes"
    done
}

# 3a. Full Backup
full_backup() {
    mkdir -p $BACKUP_DIR
    tar czf $BACKUP_DIR/full_backup_$(date +%F).tar.gz -C $WEB_ROOT .
    echo "Full backup completed."
}

# 3b. Incremental Backup and S3 Sync
incremental_backup() {
    mkdir -p $BACKUP_DIR
    tar --listed-incremental=$INCREMENTAL_FILE -czf $BACKUP_DIR/incremental_backup_$(date +%F).tar.gz -C $WEB_ROOT .
    echo "Incremental backup completed."
    # Sync to S3 (requires awscli and configured credentials)
    aws s3 cp $BACKUP_DIR/incremental_backup_$(date +%F).tar.gz $S3_BUCKET/
    echo "Backup synced to S3."
}

# Main menu
case "$1" in
    deploy) deploy_prestashop ;;
    analyze) analyze_website ;;
    full-backup) full_backup ;;
    incremental-backup) incremental_backup ;;
    *) echo "Usage: $0 {deploy|analyze|full-backup|incremental-backup}" ;;
esac
