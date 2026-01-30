# PIO WordPress Plugin

This plugin displays members from the PIO API on WordPress pages using shortcodes.

## Install
1. Copy this folder to `wp-content/plugins/pio-oremis`.
2. Activate **PIO OREMIS API** in WordPress.
3. Go to **Settings > PIO OREMIS** and set the API base URL and token if needed.

## Shortcode
```
[pio_team code="CA-OFFICIER"]
```

### Attributes
- `code` (string) Team code, e.g. `CA-OFFICIER`.
- `show_email` (0|1) Show email addresses.
- `show_cib` (0|1) Show CIB codes.
- `layout` (cards|list) Display mode.
- `columns` (1-6) Columns in cards layout.
- `limit` (number) Limit number of members.
- `class` (string) Extra CSS class on wrapper.
- `debug` (0|1) Show API errors to admins.

### Example
```
[pio_team code="CA-OFFICIER" show_email="1" columns="2"]
```

## Cache
Responses are cached using transients. Use `?pio_refresh=1` (admins only) to force refresh.

