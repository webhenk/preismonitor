# PreisMonitor

PreisMonitor ist jetzt eine **Web-UI** zum Analysieren und Überwachen von Hotel-/Zimmerpreisen. Du gibst eine URL ein, klickst auf **Analyse** und bekommst den Preis. Optional kannst du das Monitoring aktivieren, damit der Preis täglich geprüft und persistent gespeichert wird.

## Anforderungen

- PHP 8.1+
- cURL Extension aktiviert

## Lokaler Start (Web UI)

```bash
php -S 0.0.0.0:8080 -t public
```

Danach unter `http://localhost:8080` öffnen.

## Docker (Webservice)

```bash
docker build -t preismonitor .
docker run --rm -p 8080:8080 preismonitor
```

## Monitoring (täglicher Check)

Die tägliche Prüfung läuft über einen Cron-Job. Der Webservice stellt dafür den Endpoint `/cron.php` bereit:

```
GET /cron.php
```

Beispiel für Render:
- Webservice startet mit dem Dockerfile.
- Separater Render Cron Job ruft täglich `https://<dein-service>.onrender.com/cron.php` auf.
- Build Command (Render): `npm install`
- Post-Install: `npx playwright install --with-deps chromium`

Die Daten werden persistent in `data/` gespeichert:

- `data/monitors.json` (Monitoring-URLs)
- `data/history.json` (Preis-Historie)

## Konfiguration

Alle Einstellungen liegen in `config/settings.json` (z. B. User-Agent, Timeout).

```json
{
  "user_agent": "PreisMonitor/1.0",
  "timeout_seconds": 20
}
```
