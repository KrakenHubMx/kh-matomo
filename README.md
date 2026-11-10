# KH Matomo for YOURLS

[![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

KH Matomo records clicks on YOURLS short links as Matomo events. Tracking runs
on your server, so you do not need JavaScript on the destination website.
You can also track requests for short links that do not exist.

## Features

- Records successful short-link redirects and optionally missing keywords.
- Tests the Matomo connection without changing saved settings.
- Anonymizes visitor IP addresses by default.
- Shows sampled delivery results and a short history of successes and failures.
- Uses YOURLS date and timezone settings, including the Timezones plugin.
- Keeps redirects working when Matomo is unavailable.

## Requirements

- YOURLS 1.10.x; the development reference is YOURLS 1.10.6.
- PHP 8.1 or newer with cURL.
- Matomo Cloud or Matomo 5/6 self-hosted, accessible over HTTPS.
- A Matomo token with write or administrator permission for the selected site.

Use a dedicated Matomo user with write access only to that site. Administrator
permission is accepted but is not required. Matomo's CustomVariables plugin is
needed only to process the additional destination metadata, not the events.

## Install

1. Copy the plugin into `user/plugins/kh-matomo/`, with `plugin.php` at the
   plugin directory root.
2. Activate **KH Matomo Tracker** on the YOURLS Plugins page.
3. Open **Matomo Tracker** to configure it.

## Configure

See the [configuration guide](docs/plugin-configuration.md) for setup instructions and
details on each setting.

## Events in Matomo

Both event types use category `YOURLS`:

| Request | Event action | Event name | Action URL | Destination metadata |
|---|---|---|---|---|
| Existing short link | `redirect` | Keyword | Short URL visited | Full original destination in `YOURLS destination` |
| Eligible missing keyword | `keyword_not_found` | Missing keyword | Short URL formed from the keyword | None |

Both can include a valid HTTP(S) referrer, the visitor's User-Agent, and an IP
address masked according to your settings. Referrer query strings are not
removed by the missing-keyword filter.

To check a new event, select the configured site and current date in Matomo,
then open **Visitors → Visits Log / Live**. Visit a valid short link and look
for `YOURLS / redirect` with its keyword. To check missing-keyword tracking,
request an unused keyword after enabling both tracking options. Standard
reports may appear later through Matomo archiving.

The action URL uses your configured YOURLS address. Local development visits
therefore show URLs such as `http://localhost:8080/abc`; production visits use
the public short-link domain. Several actions can appear within one visit;
server-side tracking does not share browser cookies and visitor identification
may be less precise.

### Destination metadata

The full destination URL is sent as the action custom variable
`YOURLS destination`, preserving the original behavior. Matomo needs the
**CustomVariables** plugin to process it. **CustomDimensions** is a separate
plugin and does not automatically process `cvar`.

With CustomVariables available, inspect the action URL tooltip in the Visits
Log or the Custom Variables reports. The 200-character value limit can truncate
long destinations. Seeing the event alone does not confirm that its destination
metadata was stored. CustomDimensions integration is not included.

## Delivery diagnostics

The panel shows a recorded success and failure, with HTTP status, cURL error
number, duration, and timestamp. Both `redirect` and `keyword_not_found`
attempts share these samples; the cards do not identify the event type.

A successful HTTP response means the endpoint answered. It does not prove the
event was accepted into Matomo reports. Connection tests, disabled tracking,
and filtered or quota-limited requests do not update delivery samples.

### History and quota indicator

The history holds up to ten success and ten failure samples, newest first.
For sequential requests, each outcome is saved at most once every 60 seconds;
concurrent requests can overwrite samples. The cards and history are samples,
not a complete log, exact last-attempt times, or availability statistics.

**Clear sample history** removes the history while retaining the cards and
sampling timestamps. Existing samples also remain after configuration changes
or disabling tracking, so a card may describe an earlier configuration.

A quota notice means the **latest recorded window** reached 30 missing-keyword
reservations. It may refer to an earlier minute and is replaced when a later
reservation updates that window. It does not tell you how many requests were
skipped. Excess requests do not add counter writes.

### Dates and timezones

Diagnostic dates follow YOURLS' timezone and date-format helpers. If Timezones
is active, its configuration applies. Otherwise YOURLS uses
`YOURLS_HOURS_OFFSET` from `user/config.php`, or UTC when it is not defined;
the default display format is `Y-m-d H:i:s` unless another filter changes it.
Changing the display timezone does not rewrite history, reorder events, or
change the delivery quota.

## Delivery and privacy

Tracking runs synchronously before the redirect response, with a 500 ms
connection timeout and a 1 second total timeout. A slow Matomo service can
therefore delay the response. There are no retries or persistent delivery
queues, and delivery is not guaranteed. Tracking and diagnostic-storage
failures do not prevent the redirect.

The destination and referrer may contain sensitive paths, query parameters, or
signed URLs. IP anonymization does not remove those values. Consider what your
links contain before enabling tracking.

Local delivery diagnostics store only timestamps, controlled outcome reasons,
HTTP status, cURL error numbers, and durations. They do not store tokens,
keywords, destinations, referrers, User-Agents, client IPs, or response bodies.
This describes local diagnostics; Matomo receives the event data listed above.

## Troubleshooting

| Symptom | What to check |
|---|---|
| Connection test fails | HTTPS base URL, Site ID, token permissions, cURL availability, and connectivity from the YOURLS server. |
| Connection succeeds but no events appear | Save the settings, enable tracking, and check the Matomo site/date and delivery diagnostics. The connection test sends no event. |
| Missing keyword produces no event | Both switches, GET method, keyword format, available quota, and whether the request reaches YOURLS. |
| Destination is missing | CustomVariables must be active; CustomDimensions alone does not process this field. |
| Diagnostic date differs from your expectation | YOURLS timezone configuration or the Timezones plugin settings. |

## Translations

The plugin uses YOURLS' native gettext functions and the `kh-matomo` text
domain. The `languages` directory includes English (`en_US`), Spanish for
Spain (`es_ES`) and Mexico (`es_MX`), plus the translation template
`kh-matomo.pot`. The plugin loads `kh-matomo-<locale>.mo` using the locale
configured in YOURLS. After editing a `.po` file, rebuild its `.mo` file with:

```bash
msgfmt --check --check-format \
  --output-file=languages/kh-matomo-en_US.mo \
  languages/kh-matomo-en_US.po
```

Use the corresponding locale name for other catalogs and keep each `.po` source
alongside its compiled `.mo` file.

## Update notice

The settings page checks GitHub for new stable releases and lets you know when
an update is available. Drafts and prereleases are excluded. Results are cached
for 24 hours, or 2 hours if the check fails. Updates must be installed manually;
the plugin does not download or install them.
