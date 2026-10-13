# PIO OREMIS WordPress Plugin

Tools for the OREMIS website:

- `[pio_team]`: members of a PIO department team, read from the PIO API.
- `[pv_ca_list]` / `[pv_ag_list]`: minutes (PV) of the board (CA) and general assembly (AG) meetings.
- `[upcoming_ca_list]` / `[upcoming_ag_list]`: upcoming CA and AG meetings with their agenda.

## Install
1. Download `pio-oremis.zip` from the [latest release](https://github.com/AssociationOREMIS/pio-wordpress/releases/latest).
2. In WordPress, **Plugins > Add New > Upload Plugin**, then activate **PIO OREMIS API**.
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

## Login (OREMIS SSO and Google)
Settings in **OREMIS > Connexion** (administrators only). Accounts are never created or given a role here: the Data sync does that.

- **OREMIS SSO** (sso.oremis.fr): the account is found by its CIB, stored by the Data sync in the `oremis_cib` user meta
  (shown read-only on the user profile). Register a confidential client in Data (Clients SSO) with the redirect URI shown on the settings page.
  The client secret can be set in `wp-config.php` with `define('PIO_OREMIS_SSO_CLIENT_SECRET', '...');`.
- **Google**: only verified `@oremis.fr` accounts, matched by email. This replaces the former "Google OAuth Login Standalone" plugin:
  same options, same redirect URI, so it can be deactivated with nothing to reconfigure. While it is still active, it keeps handling Google login.

Deploy order: this plugin first, then the sync (it starts writing CIBs once the meta exists), then tick "Enable" for the SSO.

## OREMIS Chat
Settings in **OREMIS > Chat** (administrators only): shows the OREMIS Chat bubble on every page, loaded from `<chat address>/embed.js`.

- **Display**: off, administrators only (to try it on the real site before the launch), or everyone.
- **Hide on these pages**: page slugs, one per line.
- `[oremis_chat_link text="Parler à un bénévole"]`: a link to the full chat page, shown only when the bubble is.

The chat only works on the sites listed in its `CORS_ORIGINS` (`https://oremis.fr,https://www.oremis.fr`): elsewhere, the browser refuses to show it.

## Updates
From 0.5.0 on, new versions show up in **Dashboard > Updates** like any other plugin (checked every 12 hours, or with "Check again").

To publish one, bump the version in `pio-oremis.php` (both the `Version:` header and `PIO_OREMIS_VERSION`) in the pull request.
Once it is merged into `main`, the "Release" workflow checks the PHP syntax, then creates the `v<version>` tag, the GitHub release
(with generated notes) and its `pio-oremis.zip`. Sites pick the update up within 12 hours.
A merge that does not bump the version publishes nothing.
