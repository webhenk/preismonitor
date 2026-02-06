# PreisMonitor

PreisMonitor is a lightweight PHP CLI tool that monitors hotel/room prices on booking pages for a specific date and stores daily results in plain text files.

## Requirements

- PHP 8.1+
- cURL extension enabled
- `mail()` configured on the host (for email alerts)

## Configuration

All settings live in `config/` and are file-based (no SQL database required).

### `config/settings.json`

```json
{
  "user_agent": "PreisMonitor/1.0",
  "timeout_seconds": 20,
  "email": {
    "enabled": false,
    "to": "alerts@example.com",
    "from": "preis-monitor@localhost",
    "subject_prefix": "[PreisMonitor]"
  }
}
```

### `config/targets.json`

```json
[
  {
    "id": "sample-hotel",
    "url": "https://example.com/search?checkin={date}",
    "date": "2024-12-31",
    "rooms": [
      {
        "name": "Deluxe Queen",
        "room_hint": "Deluxe Queen",
        "price_regex": "/\\$([0-9,.]+)/",
        "threshold": 150.00
      }
    ]
  }
]
```

**Fields**

- `id`: Identifier for the hotel or booking source.
- `url`: URL to monitor. Use `{date}` as a placeholder for the date.
- `date`: Date to query (YYYY-MM-DD). Omit to default to today.
- `rooms`: List of room definitions.
  - `name`: Room name label.
  - `room_hint`: Optional text snippet to narrow the search region in the HTML.
  - `price_regex`: Optional regex used to extract the total price. If omitted, a default currency pattern is used. Capture group 1 should contain the numeric value to normalize.
  - `threshold`: Optional alert threshold for triggering emails.

## Usage

Run the CLI script:

```bash
php monitor.php
```

Results are stored daily in `data/YYYY-MM-DD.txt` as pipe-delimited lines:

```
2024-03-31T10:00:00+00:00 | sample-hotel | Deluxe Queen | $129.00 | 129 | https://example.com/search?checkin=2024-12-31
```

## Email Alerts

Enable alerts in `config/settings.json` by setting `email.enabled` to `true`, then configure `to` and `from`. When a price is found at or below the `threshold` defined for a room, the tool triggers `mail()`.

## Notes

- Each room can use a unique regex to match the relevant HTML.
- Custom regexes should expose the numeric value in capture group 1 (e.g., `/€\\s*([0-9,.]+)/`) so the parser can normalize it.
- This tool does not parse dynamic JavaScript-rendered content. For JS-heavy sites, consider a dedicated scraping workflow.
