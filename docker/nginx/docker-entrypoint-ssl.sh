#!/bin/sh
set -e

SSL_DIR="/etc/nginx/ssl"
CERT_FILE="$SSL_DIR/cert.pem"
KEY_FILE="$SSL_DIR/key.pem"

# Auto-generate self-signed certificate if not found
if [ ! -f "$CERT_FILE" ] || [ ! -f "$KEY_FILE" ]; then
    echo "=========================================="
    echo " SSL certificate not found!"
    echo " Auto-generating self-signed certificate..."
    echo "=========================================="
    mkdir -p "$SSL_DIR"
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/C=ID/ST=Jakarta/L=Jakarta/O=IPManager Pro/CN=ipmanager.local" \
        2>/dev/null
    echo " ✅ SSL certificate generated successfully!"
    echo "    Valid for 10 years."
    echo "=========================================="
else
    echo " ✅ SSL certificate found. Starting Nginx..."
fi

# Execute the original CMD (nginx)
exec "$@"
