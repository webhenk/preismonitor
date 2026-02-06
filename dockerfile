FROM php:8.3-cli

# cURL Extension (wird laut README benötigt)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

EXPOSE 8080

# Web-UI starten
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
