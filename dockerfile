FROM php:8.3-cli

# cURL Extension (wird laut README benötigt)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Default: CLI ausführen
CMD ["php", "monitor.php"]
