SYNC_DIR="public"
FTP_HOST="$(grep 'host' .credentials | cut -d '=' -f 2)"
FTP_USERNAME="$(grep 'username' .credentials | cut -d '=' -f 2)"
FTP_OBSCURED_PASSWORD="$(grep 'password' .credentials | cut -d '=' -f 2)"
TOKEN_SECRET="$(grep 'token_secret' .credentials | cut -d '=' -f 2)"

sed -i "s/<use-a-strong-token-secret-here>/${TOKEN_SECRET}/g" "public/index.php" \
  && rclone sync --update "$SYNC_DIR" ":ftp:/$SYNC_DIR/" \
    --ftp-concurrency=10 \
    --ftp-host="$FTP_HOST" \
    --ftp-user="$FTP_USERNAME" \
    --ftp-pass="$FTP_OBSCURED_PASSWORD" \
  && sed -i "s/${TOKEN_SECRET}/<use-a-strong-token-secret-here>/g" "public/index.php"
