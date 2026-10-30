# KH Matomo for YOURLS

KH Matomo sends server-side click events from YOURLS to Matomo. It uses the
YOURLS redirect hook and Matomo's HTTP Tracking API; no JavaScript is needed on
the destination website.

## Features

- Tracks valid short-link redirects as Matomo events.
- Stores configuration in YOURLS and keeps the saved token masked.
- Lets administrators test the Matomo connection separately from saving.
- Preserves the existing token when the token field is left blank; entering a
  new token replaces it.
- Anonymizes visitor IP addresses by default before sending them to Matomo.
- Keeps tracking best effort: a Matomo error does not cancel the redirect.

## Requirements

- YOURLS 1.10.x (development environment: YOURLS 1.10.6).
- PHP 8.1 or newer with the cURL extension.
- Matomo Cloud or Matomo 5/6 self-hosted.
- A Matomo token with write or administrator permission for the selected site.

## Install

1. Copy the plugin directory into `user/plugins/kh-matomo/` so `plugin.php` is
   at the plugin directory root.
2. Activate **KH Matomo Tracker** in the YOURLS Plugins page.
3. Open **Matomo Tracker** from the YOURLS Plugins page.

Activation is a manual administrator action. The plugin does not activate
itself.

## Configure

Enter the Matomo base URL, Site ID, and authentication token. The base URL is
the Matomo domain, optionally followed by its installation directory. Do not
append `/matomo.php` or `/index.php`; the plugin adds the correct endpoint.

Examples:

- `https://analytics.example.com`
- `https://example.com/matomo/`
- `https://example.matomo.cloud/`

The **Test connection** button is optional when saving. Leave the token field
blank to keep the stored token. Enter a replacement token only when you want to
change it. The connection test accepts both site write and administrator
permissions.

After saving, enable **Habilitar seguimiento** to send redirect events. IP
anonymization is enabled by default: IPv4 is reduced to `/24` and IPv6 to `/64`
before transmission. Disabling that setting sends the full client IP to Matomo.

## Event data

Each tracked redirect sends:

| Matomo field | Value |
|---|---|
| Event category | `YOURLS` |
| Event action | `redirect` |
| Event name | Short-link keyword |
| Action URL | Short URL visited |
| Action custom variable | Original destination URL, named `YOURLS destination` |
| Referrer | Request referrer, if present |
| User-Agent | Visitor's User-Agent, if present |
| Client IP | Masked by default |

To verify a click immediately, select the configured site in Matomo and inspect
**Visitors → Visits Log / Live**. Expand a visit and look for the `YOURLS`
event. Standard reports may update later through Matomo archiving. In a local
YOURLS environment, the action URL will use the local host (for example,
`localhost:8080`); a production environment uses its configured public YOURLS
URL.

## Delivery and privacy

Tracking runs synchronously before YOURLS sends the redirect response. The
request has a 500 ms connection timeout and a 1 second total timeout. A slow or
unavailable Matomo service can add up to that bounded wait, but tracking errors
do not prevent the redirect. Delivery is best effort and is not guaranteed.

The destination URL is included in Matomo as a custom variable. Avoid tracking
links whose query strings contain information that should not be stored in
analytics.

## Code layout

- `plugin.php`: plugin entry point and YOURLS hook registration.
- `includes/admin.php`: settings page, validation, and save flow.
- `includes/settings.php`: defaults and YOURLS option persistence.
- `includes/tracker.php`: redirect event construction and IP anonymization.
- `includes/matomo-api.php`: Matomo Reporting API and Tracking API requests.
- [`docs/CONTRACT.md`](docs/CONTRACT.md): behavior, compatibility, privacy, and UAT acceptance contract.

## UAT status

Connection setup, saving, and synthetic event delivery to Matomo Live have been
checked in the development environment. UAT still needs to verify a real HTTP
click on a YOURLS short link and confirm that Matomo receives its keyword and
event details.
