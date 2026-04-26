# Formula SAE Brasil phpBB instance
FROM baja-phpbb-base:latest

# Bake in the dev seed config.php — entrypoint copies it to the volume
# on first boot if no config.php exists yet
COPY config.php.seed /var/www/html/config.php.seed
