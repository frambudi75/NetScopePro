FROM nginx:alpine

# Install openssl for auto-generating self-signed certificates
RUN apk add --no-cache openssl

COPY docker-entrypoint-ssl.sh /docker-entrypoint-ssl.sh
RUN chmod +x /docker-entrypoint-ssl.sh

ENTRYPOINT ["/docker-entrypoint-ssl.sh"]
CMD ["nginx", "-g", "daemon off;"]
