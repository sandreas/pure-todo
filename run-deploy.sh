SYNC_DIR="public"
FTP_HOST="$(grep 'host' .credentials | cut -d '=' -f 2)"
FTP_USERNAME="$(grep 'username' .credentials | cut -d '=' -f 2)"
FTP_OBSCURED_PASSWORD="$(grep 'password' .credentials | cut -d '=' -f 2)"
rclone sync --update "$SYNC_DIR" ":ftp:/$SYNC_DIR/" \
    --ftp-concurrency=10 \
    --ftp-host="$FTP_HOST" \
    --ftp-user="$FTP_USERNAME" \
    --ftp-pass="$FTP_OBSCURED_PASSWORD"