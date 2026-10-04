FROM alpine:latest

# Run latest security update. 
RUN apk --no-cache upgrade
# Install dependencies
RUN apk add --no-cache \
    php-cli \
    php-curl \
    php-json \
    php-posix \
    php-mbstring

# Copy progtam
COPY skodaLnxCmd.php /usr/local/bin/
COPY HomeAssistant/ha_server.php /var/www/
RUN chmod +x /usr/local/bin/skodaLnxCmd.php && \
    ln -s /usr/local/bin/skodaLnxCmd.php /usr/local/bin/skodaLnxCmd
