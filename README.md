# PIO OREMIS WordPress Plugin

Tools for the OREMIS website:

- `[pio_team]`: members of a PIO department team, read from the PIO API.
- `[pv_ca_list]` / `[pv_ag_list]`: minutes (PV) of the board (CA) and general assembly (AG) meetings.
- `[upcoming_ca_list]` / `[upcoming_ag_list]`: upcoming CA and AG meetings with their agenda.

## Install
1. Copy this folder to `wp-content/plugins/pio-oremis`.
2. Activate **PIO OREMIS API** in WordPress.
3. Go to **OREMIS > Config PIO** (administrators only) and set the API base URL and token if needed.
   The token can also be set in `wp-config.php` with `define('PIO_OREMIS_API_TOKEN', '...');`.
   A saved token is never shown again: leave the field empty to keep it.

## Team members
```
[pio_team code="CA-OFFICIER"]
```

### Attributes
- `code` (string) Team code, e.g. `CA-OFFICIER`. Defaults to the "Default Team Code" setting.
- `show_email` (0|1) Show email addresses.
- `show_cib` (0|1) Show CIB codes.
- `layout` (cards|list) Display mode.
- `columns` (1-6) Columns in cards layout.
- `limit` (number) Limit number of members.
- `class` (string) Extra CSS class on wrapper.

### Example
```
[pio_team code="CA-OFFICIER" show_email="1" columns="2"]
```

### Cache
Responses are cached using transients for "Cache TTL" seconds. Use `?pio_refresh=1` (admins only) to force a refresh.

If the API fails, the last members received are shown instead and the API is not called again for 2 minutes.
Administrators see the API error above the list, visitors see nothing unusual.

## Minutes and upcoming meetings
Entries are managed under the **OREMIS** admin menu (users who can upload files).

```
[pv_ca_list]
[pv_ag_list posts_per_page="20"]
[upcoming_ca_list show_pagination="0"]
```

### Attributes
- `posts_per_page` (1-100) Items per page, 10 by default.
- `show_pagination` (0|1) Numbered pagination, on by default.
- `heading_level` (2-6) Heading level of each item, 3 by default (`<h3>`).

Minutes are sorted by meeting date, newest first. Minutes without a date are listed last.
An upcoming meeting stays visible until the end of the day after it.
