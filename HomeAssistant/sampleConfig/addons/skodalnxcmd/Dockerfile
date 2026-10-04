ARG container_VER=latest
FROM ghcr.io/mem76/skodalnxcmd:$container_VER
RUN apk --no-cache upgrade
#CMD ["sleep", "infinity"]
CMD ["php", "-S", "0.0.0.0:8080", "/var/www/ha_server.php"]