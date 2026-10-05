# Human Verify – Anti-Bot Verification for phpBB

**Version:** 1.0.10  
**Author:** Salvo Cortesiano – Le Ombre della Rete 360° (info@netshadows.de)  
**Requirements:** phpBB 3.3.0 – 3.3.x (tested up to 3.3.19), PHP 7.4 or higher (8.2 recommended), GD extension for CAPTCHA and puzzle challenges  
**License:** GPL-2.0  

Human Verify presents visitors with a Cloudflare-style verification page before they access the board. It blocks AI scrapers and automated tools while keeping an IP address log. No external accounts, API keys, or third-party services are required—everything runs locally on your server.

---

## 1. Installation

1. Copy the `salvocortesiano/humanverify` folder into the board's `ext/` directory. The final path must be `ext/salvocortesiano/humanverify/`.
2. Navigate to **ACP › Customize › Extension management** and enable **Human Verify – Anti-bot verification**.
3. Go to **ACP › Extensions › Human Verify › Check-up** and run the diagnostic check-up.
4. If all checks pass, go to **Settings** and enable verification.

After installation, verification is **disabled by default** so you can review your configuration before going live.

## 2. How It Works

1. On every request, the extension inspects the visitor immediately after the phpBB session initializes (`core.user_setup_after` event).
2. If the visitor lacks a valid pass, they receive the verification page instead of the requested page.
3. The visitor's browser executes a **proof-of-work computation** (SHA-256): it must calculate a nonce that, when combined with a seed, produces a hash starting with $N$ leading zeros. This takes a real browser a fraction of a second. For automated bots attempting to scrape thousands of pages, it imposes a heavy computational cost, and non-JavaScript clients fail automatically.
4. Based on the selected ACP mode, an additional interactive challenge (checkbox, CAPTCHA, or sliding puzzle) may be required.
5. Upon successful verification, the server sets a signed cookie (`<phpBB cookie name>_hv`), and the visitor is redirected back to their intended target page.

Challenges are **signed via HMAC-SHA256** using a secret key generated during installation, requiring no server-side challenge storage. Each challenge expires after 10 minutes and is strictly **single-use**. Challenges are bound to the visitor's browser (`User-Agent`) and, optionally, their IP address.

## 3. Challenge Types

| Type | Visitor Experience | Requires GD |
|---|---|---|
| **Automatic** | Displays a "Verifying..." spinner, then grants seamless access | No |
| **Interactive Checkbox** | Requires clicking a "Verify you are human" checkbox | No |
| **CAPTCHA** | Requires entering a distorted text code (4–8 alphanumeric characters, case-insensitive) | Yes |
| **Image Puzzle** | Requires solving a 3×3 or 4×4 tile sliding image puzzle | Yes |

*Note: The background proof-of-work computation executes across all modes. It begins processing instantly as the page opens, typically completing before the visitor clicks.*

In **Puzzle** mode, tiles can be swapped via drag-and-drop using a mouse, touch, or stylus. Alternatively, tapping two tiles sequentially swaps them, allowing full accessibility via keyboard navigation (Tab and Enter keys). An optional thumbnail preview of the solved image can be displayed alongside the puzzle.

The puzzle generator uses randomly rendered landscape patterns by default. To use **custom images**, upload JPG or PNG files to `ext/salvocortesiano/humanverify/images/puzzle/`. A random image will be selected and center-cropped into a square ratio automatically.

If the GD extension is missing or disabled, CAPTCHA and Puzzle modes gracefully fall back to the interactive checkbox challenge.

## 4. Configuration Settings (ACP › Human Verify › Settings)

### Visitor Verification

- **Enable verification:** Master toggle to turn protection on or off.
- **Verification type:** Choose from the four operational modes described above.
- **Verification frequency:** Sets pass validity duration in hours. Quick presets range from "Every session" (0) to "30 days" (720 hours). Custom values up to 8760 hours are supported.
- **Proof-of-work difficulty:** Scaled from 1 to 5; each increment multiplies required computation work by 16. **Level 4** is the recommended baseline. Level 5 may cause noticeable load times on low-powered mobile devices.
- **Exclude logged-in users:** When enabled (default), authenticated board members bypass verification pages completely.
- **Bind verification to IP address:** When enabled, changing IP addresses invalidates active passes. IPv6 tracking operates on a `/64` prefix basis.

### Bot Detection & Interception

- **Block known bots:** User agents matching the blocklist are immediately rejected with a `403 Forbidden` response.
- **Block missing User-Agent requests:** Drops requests lacking a `User-Agent` header, typically associated with raw scripts.
- **User-Agent blocklist:** Line-separated entries using partial substring matching. Lines beginning with `#` are treated as comments. Default blocklists include:
  - **AI Scrapers:** GPTBot, ClaudeBot, CCBot, Bytespider, PerplexityBot, Amazonbot, meta-externalagent, Google-Extended, and others.
  - **Aggressive SEO Crawlers:** Semrush, Ahrefs, MJ12, and others.
  - **Automated Tooling:** curl, wget, python-requests, Go-http-client, HeadlessChrome, and others.
  
  Click **Reset default blocklist** to restore default patterns at any time.
- **Allow search engines:** Allows legitimate bots indexed under **ACP › General › Spiders/Robots** to bypass verification. **Keep this enabled** to protect search indexing.
- **Verify search engine authenticity:** Performs reverse and forward DNS checks to ensure crawlers identifying as Googlebot, Bingbot, Yandex, Baidu, Applebot, or DuckDuckBot originate from official IP blocks. Spoofed bots are either blocked or routed to verification depending on blocking preferences. Results are cached per IP for 24 hours.
- **IP Whitelist:** Exclude specific IP addresses or CIDR ranges (IPv4/IPv6) from verification or blocking. Useful for administrator IPs, external monitoring tools, or search indexing proxies like Meilisearch.

### CAPTCHA & Puzzle Settings

- **CAPTCHA code length:** Configurable from 4 to 8 characters. Ambiguous character sets (e.g., `0`/`O`, `1`/`I`/`L`) are excluded to avoid user error.
- **Puzzle dimensions:** Select either a 3×3 or 4×4 grid layout.
- **Show reference image:** Displays a miniature preview of the solved image next to the puzzle.

### Page Appearance Settings

Verification and Access Denied templates share unified styling:
- Forum title positioned at top-left, with an optional logo rendered directly underneath.
- Serif display headers accompanied by custom vector illustration and soft-drop shadows.
- System metadata block displaying Request ID, IP address, and timestamp.
- Automatic system-level dark mode support.
- Fully responsive single-column mobile layout.

The central illustration features an animated shield element reflecting verification status. During checks, a scanline animation sweeps the shield alongside a pulsing status indicator. Successful verification transitions the indicator to green with a checkmark. Rejections or errors switch the status indicator to red with an error icon. Systems with reduced motion enabled (`prefers-reduced-motion`) render static states automatically.

Access Denied pages include a **Contact Administrator** action button pre-filling the board contact email and appending the active Request ID to the subject line.

- **Accent color:** Defines primary colors for interactive buttons, borders, and scanning effects. The default hex value (`#22577a`) matches unified board UI themes.
- **Logo URL:** Specify an absolute image URL (`https://...`) or relative web path (`/path/to/image`). Rendered beneath the board title. Leave blank to hide.
- **Oval logo mask:** Toggle rounded corner masks. Square assets wrap into circular avatars; rectangular assets wrap into oval designs.

### IP Logging & Temporary Banning

- **Enable IP logging:** Toggles local database logging of challenge attempts.
- **Log retention period:** Defines retention length in days before automated cron cleanup (set to `0` to disable automatic deletion).
- **Pruning frequency:** Configures phpBB cron cleanup interval settings (minimum 5 minutes, maximum 30 days; default set to every 24 hours).
- **Failed attempt threshold & Ban duration:** Reaching $N$ consecutive failed verification attempts triggers an automatic $X$-minute IP block returning `403 Forbidden`.
  - Passing a verification challenge resets failure counters immediately.
  - Failure counters reset automatically if the elapsed time since the last failure exceeds the ban duration window.
  - Visitors holding active, valid passes are never blocked, even when sharing a public IP with a blocked actor.

### Live Preview Mode

Preview links are provided beneath verification types in the ACP. These links render functional verification templates in a new tab without logging IP data or requiring pass generation, allowing testing even when verification is disabled or when logged in as an administrator.

### Global Pass Invalidation

Invalidates all currently active guest passes board-wide. Forces every unauthenticated guest visitor to complete verification on their next page navigation. Useful for mitigating active bot traffic surges.

*Note: Authenticated users automatically receive updated passes without challenges. Testing global resets should be conducted via private browsing windows or secondary guest browser sessions.*

## 5. IP Log Management (ACP › Human Verify › IP Log)

- Summary counters display total recorded IPs, total verification pages served, passed challenges, failed attempts, and active temporary blocks.
- Supports search indexing (full or partial IP matches) and filtering by challenge outcome status.
- Data tables feature sortable header columns.
- Table columns provide:
  - First and last recorded activity timestamps.
  - Counter breakdowns for attempt metrics.
  - Latest challenge result alongside used verification type.
  - Full `User-Agent` strings via hover tooltips.
  - Direct Whois lookup links.
  - Status badges denoting active temporary IP bans.
- Available record actions:
  - **Delete:** Removes an individual IP entry.
  - **Delete Selected:** Batch deletes marked entries.
  - **Unban Selected:** Clears active temporary blocks on marked entries.
  - **Empty Log:** Purges entire log table following confirmation.
- Administrative log actions are audited directly within the phpBB Administrator Log.

## 6. Diagnostic Check-up Tool (ACP › Human Verify › Check-up)

Executes 16 real-time structural and configuration tests, reporting step-by-step progress metrics and diagnostic logs:

1. PHP version check.
2. phpBB version check.
3. Core configuration inspection (including SEO crawlers visibility warnings).
4. Cryptographic function verification.
5. HMAC token signing, cookie integrity, and tampered payload rejection checks.
6. Proof-of-work performance benchmarking and execution estimates.
7. GD graphics library availability checks.
8. CAPTCHA generation and string validation checks.
9. Puzzle image rendering and tile solution validation checks.
10. Database log table read/write/delete query checks.
11. Challenge token cache re-use prevention checks.
12. Cookie configuration audit (warns if HTTPS is active but phpBB secure cookies are disabled).
13. Extension filesystem integrity inspection.
14. Translation array matching between Italian and English language files.
15. User-Agent blocklist integrity and whitelist rule syntax validation.
16. Automated pruning task audit: evaluates phpBB cron health. Identifies configuration mismatches (e.g., boards set to system cron lacking active crontabs, common on local development environments or shared web hosts like Altervista). Validates task scheduling state and verifies session maintenance activity. Performs a test prune run if no prior executions are recorded.

### Manual Cron Tools

- **Prune Now:** Immediately purges IP log entries exceeding configured retention windows and reports deleted record counts.
- **Run Cron Tasks:** Manually triggers queued phpBB core and extension cron tasks sequentially with a visual progress indicator.
  - Respects phpBB cron locking mechanisms to prevent concurrent task collisions.
  - Displays task execution timing metrics and completion statuses.
  - Provides administrative overrides when automated board crons are stalled.

## 7. Execution Context & Page Routing

Verification challenges display **in-place at the requested URL** without external HTTP redirects during initial unauthenticated visits (covering index, forum displays, topic views, member profiles, search queries, login attempts, and registration forms). Following successful completion, the target resource renders normally.

Request routing evaluates criteria sequentially in the following order:

1. **Pass Condition:** Verification disabled, route excluded, or IP whitelisted -> Grant access.
2. **Block Condition:** User-Agent matches blocklist or header is missing -> Render Access Denied (`403`).
3. **Search Engine Condition:** Known search bot recognized by phpBB -> Grant access. If reverse DNS validation is enabled and fails -> Block request.
4. **Authenticated Session Condition:** Logged-in user (with "Exclude logged-in users" active) -> Silent pass issuance and access granted.
5. **Active Pass Condition:** Valid verification cookie detected -> Grant access. Evaluated prior to temporary ban checks to protect users on shared IP networks (CGNAT/corporate proxies).
6. **Temporary Ban Condition:** Consecutive failure limit reached for IP -> Render Access Denied (`403`).
7. **Challenge Fallback:** Serves verification page.

## 8. Excluded Paths

Verification is disabled by default on the following core endpoints:
- Administration Control Panel (ACP)
- phpBB Cron runners (`cron.php`, `app.php/cron/...`)
- Syndication feeds (`app.php/feed`)
- Attachment and avatar delivery (`download/file.php`)
- User logout endpoints (`ucp.php?mode=logout`)
- Command Line Interface executions (`bin/phpbbcli.php`)

In addition, active `POST` form submissions and `AJAX` requests carry a 24-hour grace window after pass expiration to prevent data loss during long form authoring sessions.

## 9. Known Technical Limitations

- **Application-Layer Scope:** This extension is not a network edge firewall. Request payloads process through PHP and phpBB layers before interception. For volumetric DDoS mitigation, upstream network protections (such as Cloudflare) remain recommended.
- **Advanced Headless Browsers:** Fully emulated browser environments (e.g., Playwright/Puppeteer with custom evasions) may pass client checks. However, required proof-of-work processing significantly increases the resource cost per request for automated scraping tools.
- **Checkbox Mode Security:** The standalone checkbox serves primarily as an interactive friction point; primary automated enforcement relies on the concurrent background proof-of-work challenge.
- **Privacy Compliance:** Storing visitor IP addresses constitutes handling personally identifiable information (PII). Ensure your board's Privacy Policy accurately reflects IP logging practices and retention windows.

## 10. File Structure

```text
salvocortesiano/humanverify/
├── composer.json, ext.php
├── acp/                 main_info.php, main_module.php
├── adm/style/           ACP templates (settings, log, check-up, header, credits)
├── config/services.yml
├── controller/          acp_controller.php
├── core/                challenge.php     – tokens, proof-of-work, CAPTCHA, puzzle, cookies
│                        bot_detector.php  – User-Agent rules, whitelists, search engine DNS
│                        ip_log.php        – IP log handling
│                        checkup.php       – Diagnostic Check-up suite
├── cron/task/           prune_log.php     – Automated log pruning worker
├── event/               main_listener.php – Request interception event hooks
├── images/puzzle/       (Optional) Custom puzzle source images
├── language/it, en/     common.php, info_acp_humanverify.php
├── migrations/          install_v100.php
└── styles/all/          Template, CSS, and JS assets for verification pages
```

## 11. Uninstallation Procedure

1. Navigate to **ACP › Customize › Extension management** and disable **Human Verify**.
2. Click **Delete data** to purge the `phpbb_hv_log` table and associated extension settings.
3. Remove the directory `ext/salvocortesiano/humanverify/` from your web server.

## 12. Changelog

- **1.0.10**
  - Valid passes are now checked before temporary IP ban evaluation, preventing legitimate users on shared networks (e.g., mobile carriers, corporate offices) from being blocked by bad actors.
  - Failed attempt counters now reset automatically after the ban duration window expires, preventing re-banning on a single subsequent failure.
- **1.0.9**
  - Fixed board title layout hierarchy to keep board name fixed at top-left with logos rendering underneath.
  - Introduced *Oval logo mask* configuration toggle.
  - Added *Reset default* option for custom accent colors.
- **1.0.8** – Fixed issue where verification pages rendered without CSS styling or JavaScript dependencies due to path resolution errors introduced in 1.0.3.
- **1.0.7** – Introduced live verification page previews in ACP for all challenge types. Clarified behavior of *Global Pass Invalidation* when acting on authenticated administrative sessions.
- **1.0.6**
  - Added configurable automated pruning frequency settings.
  - Integrated manual log pruning and global cron task execution tools directly into the Diagnostic Check-up suite.
  - Enhanced cron diagnostic reporting to detect missing system crontabs.
- **1.0.5** – Fixed ACP accent color picker input validation and value synchronization issues.
- **1.0.4**
  - Enabled silent pass issuance for authenticated users to eliminate verification prompts post-logout or upon session expiry.
  - Added explicit exclusions for logout endpoints (`ucp.php?mode=logout`).
- **1.0.3**
  - Redesigned verification and Access Denied UI layouts with unified vector graphics, scanning animations, and dark mode support.
  - Added accent color customization and logo URL options.
  - Added pre-filled "Contact Administrator" button on Access Denied templates.
- **1.0.2**
  - Fixed permission errors during cookie diagnostic checks.
  - Updated pruning diagnostics to distinguish fresh extension installations from stalled crons.
  - Resolved issue where proof-of-work execution benchmarks reported `0 ms`.
- **1.0.1** – Ensured localized ACP module titles persist correctly in board logs upon extension installation.
- **1.0.0** – Initial release.