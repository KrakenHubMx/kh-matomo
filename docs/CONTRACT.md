# KH Matomo Plugin Contract

**Contract version:** 1.0  
**Status:** Approved for UAT validation  
**Plugin version:** 0.1.1-alpha

This document defines the plugin's required behavior, compatibility targets,
privacy rules, and UAT acceptance criteria. The user-facing setup guide is in
the repository root `README.md`.

## 1. Purpose

The plugin records valid YOURLS short-link redirects in Matomo using Matomo's
HTTP Tracking API. Tracking runs server-side and does not require JavaScript on
the destination site or in the visitor's browser.

The plugin is best effort. A Matomo error must never cancel a YOURLS redirect.

## 2. Compatibility and dependencies

### YOURLS

- Supported target: YOURLS 1.10.x.
- Development reference: YOURLS 1.10.6.
- Redirect integration uses the official `redirect_shorturl` action with the
  destination URL and short keyword.
- The plugin must not modify YOURLS core files or depend on undocumented hooks.

### PHP

- Minimum target: PHP 8.1.
- Required extension: cURL.
- Compatibility targets: PHP 8.1, 8.2, 8.3, and 8.4.

### Matomo

- Target deployments: Matomo Cloud, Matomo 5 self-hosted, and Matomo 6
  self-hosted.
- Use the standard Reporting API for connection checks and the HTTP Tracking
  API for events.
- The tracking token must have write or administrator permission for the
  configured site.

## 3. Architecture and storage

The plugin consists of:

- `plugin.php`: entry point, module loading, and YOURLS hook registration.
- `includes/admin.php`: administration page, validation, and save flow.
- `includes/settings.php`: configuration defaults, normalization, and storage.
- `includes/tracker.php`: redirect event construction and privacy handling.
- `includes/matomo-api.php`: Matomo Reporting API and Tracking API transport.
- `includes/i18n.php`: YOURLS gettext domain and locale catalog loading.

User-facing plugin strings use YOURLS' native gettext functions with the
`kh-matomo` domain. Locale catalogs use
`languages/kh-matomo-<locale>.mo`; the translation template is
`languages/kh-matomo.pot`.

Settings are stored in the YOURLS option `kh_matomo_settings`. The option is a
single configuration array; the plugin creates no additional database tables.
Settings must survive updates and deactivation. Deleting plugin files must not
implicitly remove settings.

## 4. Configuration and save behavior

Configuration is independent in each YOURLS installation. The plugin must not
assume DEV, UAT, or PROD values.

| Setting | Default | Meaning |
|---|---|---|
| `matomo_url` | Empty | HTTPS base URL of the Matomo installation |
| `site_id` | Empty | Matomo site ID |
| `auth_token` | Empty | Matomo token stored as a secret |
| `tracking_enabled` | `false` | Enables redirect event submission |
| `anonymize_ip` | `true` | Masks the visitor IP before submission |

The Matomo base URL may include the installation directory and an optional
trailing slash. It must not include `/matomo.php` or `/index.php`; the plugin
appends the relevant endpoint itself.

Saving and testing the connection are independent operations:

- Saving settings must not require a successful connection test.
- A blank token field means retain the token already stored in YOURLS.
- A non-empty token field replaces the stored token with that submitted value.
- URL and Site ID input still receive local validation before saving.
- Saving must use YOURLS authentication and a native nonce for CSRF protection.
- A failed connection test must not change saved settings.
- The token is never prefilled in the password field and is masked after saving.

The connection test checks that the site is listed by either
`SitesManager.getSitesIdWithWriteAccess` or
`SitesManager.getSitesIdWithAdminAccess`. Admin permissions must not be rejected
because the site is absent from the write-only list.

## 5. Event contract

For each valid short URL redirect, when tracking is enabled, submit one Matomo
event with this mapping:

| Matomo field | Value |
|---|---|
| Event category (`e_c`) | `YOURLS` |
| Event action (`e_a`) | `redirect` |
| Event name (`e_n`) | YOURLS short keyword |
| Action URL (`url`) | Short URL formed from the configured YOURLS site and keyword |
| Action custom variable (`cvar`) | `YOURLS destination` with the original destination URL |
| Referrer (`urlref`) | Valid HTTP(S) referrer, if supplied by the request |
| User-Agent (`ua`) | Visitor's User-Agent, never the server's User-Agent |
| Client IP (`cip`) | Visitor IP, masked according to `anonymize_ip` |

The event is sent to the Matomo `matomo.php` HTTP Tracking API with the
configured `idsite`, `rec=1`, `apiv=1`, and token authentication. The Matomo API
defines `token_auth` tracking requests as requiring write or administrator
permission for that site.

Do not invent referrer or browser information. Do not log credentials or
visitor IP addresses.

## 6. Privacy

- IP anonymization is enabled by default.
- When enabled, IPv4 addresses are reduced to `/24` and IPv6 addresses to `/64`
  before they are sent to Matomo.
- When anonymization is disabled, the full client IP may be sent to Matomo.
- Use YOURLS' client IP helper so configured trusted proxies are respected.
- The original destination URL is included in Matomo as an action custom
  variable; destination query strings may contain sensitive data and should be
  considered when choosing links to track.
- Server-side tracking does not share browser cookies and may reduce visitor
  uniqueness accuracy.

## 7. Transport and failure behavior

- Use PHP cURL with TLS verification enabled.
- Tracking is synchronous before YOURLS emits the redirect response.
- The current transport has a 500 ms connection timeout and 1 second total
  timeout. This is a bound, not a delivery guarantee; a slow Matomo response can
  cause cURL to time out even after Matomo has recorded the event.
- Do not retry indefinitely or add a persistent queue or external service.
- Tracking exceptions and failures must not prevent the redirect or display an
  error to the visitor.
- Do not claim fire-and-forget delivery unless it has been demonstrated for
  every supported PHP server model.

## 8. Administration and security

- Register the administrative page through `yourls_register_plugin_page()`.
- Rely on YOURLS authentication and nonce protection; YOURLS 1.10.x has no
  granular role/capability API for this plugin.
- Escape rendered values and validate user input.
- Keep credentials out of URLs, HTML values, logs, errors, and release artifacts.
- Diagnostic output may include safe transport metadata but must not contain
  tokens, destination URLs, referrers, or client IPs.
- Plugin-owned global identifiers use the `kh_` or `KH_MATOMO_` prefix.

## 9. UAT validation

UAT must verify:

1. The plugin activates in YOURLS 1.10.6 and registers the redirect hook with
   both callback arguments.
2. A saved token is retained when the password field is blank; a submitted new
   token replaces it.
3. Checkbox-only changes can be saved without rerunning the connection test.
4. Connection testing accepts both Matomo write and administrator permissions.
5. A real YOURLS short-link visit appears in Matomo Live with category `YOURLS`,
   action `redirect`, and the actual keyword.
6. The short URL appears as Matomo's action URL; the destination is available in
   the `YOURLS destination` action custom variable.
7. Matomo being unavailable or slow does not prevent the YOURLS redirect.
8. Anonymized and full-IP modes behave as described, without logging the IP.
9. The plugin works with PHP 8.1 through 8.4 and the targeted Matomo versions.

Current development checks confirmed PHP syntax, YOURLS hook registration,
token retention/replacement validation, Matomo admin-token permission handling,
and a synthetic event in Matomo Live. A real HTTP short-link click remains a
UAT acceptance check.

## 10. Deployment and release

- Keep `plugin.php` at the repository root for the existing deployment process.
- Release with explicit version tags; deployment manifests must use explicit
  refs and must not use `latest`.
- Promote the same tested artifact from UAT to PROD.
- Activation remains a manual YOURLS administrator decision.
- Do not deploy to production before UAT validation and approval.
