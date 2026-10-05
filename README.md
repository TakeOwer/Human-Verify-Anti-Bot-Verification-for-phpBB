# Human Verify – Verifica anti-bot per phpBB

![Version](https://img.shields.io/badge/version-1.0.11-105080) ![phpBB](https://img.shields.io/badge/phpBB-3.3.x-377a33) ![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-377a33) ![License](https://img.shields.io/badge/license-GPL--2.0--only-7f7f7f)

# Human Verify – Anti-Bot Verification for phpBB

**Version:** 1.0.10  
**Author:** Salvo Cortesiano – Le Ombre della Rete 360° (info@netshadows.de)  
**Requirements:** phpBB 3.3.0 – 3.3.x (tested up to 3.3.19), PHP 7.4 or higher (8.2 recommended), GD extension for CAPTCHA and puzzle challenges  
**License:** GPL-2.0  

Human Verify presents visitors with a Cloudflare-style verification page before they access the board. It blocks AI scrapers and automated tools while keeping an IP address log. No external accounts, API keys, or third-party services are required—everything runs locally on your server.

---

<img width="1302" height="1037" alt="Screenshot 2026-10-05 093433" src="https://github.com/user-attachments/assets/b206734c-1a47-4b86-bb06-d3ff04d83356" />
---
<img width="1158" height="1084" alt="Screenshot 2026-10-05 093441" src="https://github.com/user-attachments/assets/ad993a4e-4abc-4a39-b7d7-87e389478e42" />
---
<img width="1216" height="1105" alt="Screenshot 2026-10-05 093450" src="https://github.com/user-attachments/assets/47ffe862-f649-45e4-87be-eb8af4ce6fc7" />
---
<img width="1114" height="1251" alt="Screenshot 2026-10-05 093511" src="https://github.com/user-attachments/assets/f1e40715-4492-4079-bb1a-5bc5b33b71cc" />
---
<img width="2273" height="1259" alt="Screenshot 2026-10-05 095617" src="https://github.com/user-attachments/assets/8fd4e88e-b054-4b1c-8383-44af2659911e" />
---
<img width="2289" height="981" alt="Screenshot 2026-10-05 095635" src="https://github.com/user-attachments/assets/ce157f2d-1005-424e-9c24-48550a436fff" />
---
<img width="2297" height="1237" alt="Screenshot 2026-10-05 0956578" src="https://github.com/user-attachments/assets/60d178c1-5e2c-4029-bc51-7e2c92968467" />
---
<img width="2286" height="908" alt="Screenshot 2026-10-05 095708" src="https://github.com/user-attachments/assets/640e157f-4cf6-485f-a6a0-9a59d2c59c44" />
---
<img width="2284" height="410" alt="Screenshot 2026-10-05 095729" src="https://github.com/user-attachments/assets/51c02bb9-6cf4-4b03-8910-ea9342140f08" />

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

**Versione:** 1.0.10  
**Autore:** Salvo Cortesiano – Le Ombre della Rete 360° (info@netshadows.de)  
**Requisiti:** phpBB 3.3.0 – 3.3.x (testata per 3.3.19), PHP 7.4 o superiore (consigliato 8.2), estensione GD per captcha e puzzle  
**Licenza:** GPL-2.0

Human Verify mostra ai visitatori una pagina di verifica in stile Cloudflare prima che entrino nel forum. Blocca gli scraper IA e i programmi automatici e tiene un registro degli indirizzi IP. Non servono account esterni, chiavi API o servizi di terze parti: tutto gira sul tuo server.

---

## 1. Installazione

1. Copia la cartella `salvocortesiano/humanverify` in `ext/` del forum. Il percorso finale deve essere `ext/salvocortesiano/humanverify/`.
2. In ACP › Personalizza › Gestione estensioni, attiva **Human Verify – Verifica anti-bot**.
3. Vai in ACP › Estensioni › Human Verify › **Check-up** ed esegui il check-up.
4. Se è tutto verde, vai in **Impostazioni** e attiva la verifica.

Dopo l'installazione la verifica è **spenta**, così puoi controllare tutto prima di attivarla.

## 2. Come funziona

1. A ogni richiesta l'estensione controlla il visitatore subito dopo l'avvio della sessione phpBB (evento `core.user_setup_after`).
2. Se il visitatore non ha un "pass" valido, riceve la pagina di verifica al posto della pagina richiesta.
3. Il browser risolve un **calcolo proof-of-work** (SHA-256): deve trovare un numero che, unito a un codice casuale, produca un hash che inizia con N zeri. A un browser costa una frazione di secondo. A un bot che apre migliaia di pagine costa moltissimo, e chi non esegue JavaScript non passa.
4. A seconda del tipo scelto in ACP, si aggiunge una prova manuale (casella, captcha o puzzle).
5. Se la verifica riesce, il server salva un cookie firmato (`<nome cookie phpBB>_hv`) e il visitatore torna alla pagina che voleva.

Le sfide sono **firmate con HMAC-SHA256** usando una chiave segreta generata all'installazione. Il server non deve memorizzarle. Ogni sfida scade dopo 10 minuti e si può usare **una sola volta**. È legata al browser (user-agent) e, se lo scegli, anche all'IP.

## 3. I quattro tipi di verifica

| Tipo | Cosa vede il visitatore | GD |
|---|---|---|
| **Automatica** | Una rotella "Verifica in corso…" e poi entra da solo | No |
| **Casella da cliccare** | La casella "Verifica di essere umano" da spuntare | No |
| **Captcha** | Un codice in un'immagine distorta da trascrivere (4–8 caratteri, maiuscole o minuscole) | Sì |
| **Puzzle di immagini** | Un'immagine divisa in 3×3 o 4×4 tasselli rimescolati da rimettere in ordine | Sì |

Il calcolo in background c'è **sempre**, anche negli altri tre tipi. Parte appena si apre la pagina, così di solito è già finito quando il visitatore clicca.

Nel **puzzle** i tasselli si scambiano trascinandone uno sopra un altro, con mouse, dito o penna. In alternativa si toccano due tasselli uno dopo l'altro, e questo funziona anche da tastiera con Tab e Invio. Accanto al puzzle può comparire l'immagine completa come aiuto.

Le immagini del puzzle sono paesaggi generati a caso. Per usare **foto tue**, caricale in JPG o PNG nella cartella `ext/salvocortesiano/humanverify/images/puzzle/`: ne viene scelta una a caso e ritagliata al centro in formato quadrato.

Se GD manca, captcha e puzzle ripiegano automaticamente sulla casella.

## 4. Impostazioni (ACP › Human Verify › Impostazioni)

### Verifica dei visitatori

- **Attiva la verifica:** l'interruttore generale.
- **Tipo di verifica:** uno dei quattro tipi descritti sopra.
- **Ogni quanto chiedere la verifica:** per quante ore il pass resta valido. I pulsanti rapidi vanno da "Ogni sessione" (0) a "30 giorni" (720); puoi scrivere anche un valore a mano, fino a 8760 ore.
- **Difficoltà del calcolo:** da 1 a 5, ogni livello moltiplica il lavoro per 16. Il **4** è il valore consigliato. Il 5 può far aspettare qualche secondo chi usa telefoni lenti.
- **Escludi gli utenti connessi:** chi ha fatto il login non vede la verifica (attivo di default).
- **Lega la verifica all'indirizzo IP:** se l'IP cambia, la verifica va rifatta. Per IPv6 conta il prefisso /64.

### Intercettazione dei bot

- **Blocca i bot riconosciuti:** gli user-agent dell'elenco ricevono subito "Accesso negato" (403).
- **Blocca le richieste senza user-agent:** di solito sono script.
- **User-agent da bloccare:** una voce per riga, basta una parte del nome; le righe con `#` sono commenti. L'elenco iniziale comprende:
  - scraper IA: GPTBot, ClaudeBot, CCBot, Bytespider, PerplexityBot, Amazonbot, meta-externalagent, Google-Extended e altri;
  - bot SEO aggressivi: Semrush, Ahrefs, MJ12 e altri;
  - strumenti automatici: curl, wget, python-requests, Go-http-client, HeadlessChrome e altri.
  
  Il pulsante **Ripristina l'elenco iniziale** lo riporta com'era.
- **Lascia passare i motori di ricerca:** i bot di ACP › Generale › Bot entrano senza verifica. **Lascialo attivo**, altrimenti il forum rischia di sparire da Google.
- **Controlla che i motori di ricerca siano autentici:** verifica con DNS inverso e diretto che Googlebot, Bingbot, Yandex, Baidu, Applebot e DuckDuckBot vengano davvero dai loro server. I falsi vengono bloccati, oppure mandati alla verifica se il blocco bot è spento. Il risultato resta in cache 24 ore per IP.
- **IP sempre ammessi:** indirizzi singoli o intervalli CIDR, IPv4 e IPv6, che non vengono mai verificati né bloccati. Utile per il tuo IP, per servizi di monitoraggio o per il server Meilisearch.

### Captcha e puzzle

- **Lunghezza del codice captcha:** da 4 a 8 caratteri. I caratteri che si confondono (0/O, 1/I/L) sono esclusi.
- **Tasselli del puzzle:** 3×3 oppure 4×4.
- **Mostra l'immagine completa:** la miniatura di aiuto accanto al puzzle.

### Aspetto della pagina di verifica

La pagina di verifica e la pagina "Accesso negato" usano la stessa grafica di DB Guardian:
- nome del forum in alto, con l'eventuale logo sotto;
- titolo serif con un'illustrazione a sinistra, sopra un pavimento con ombra sfumata;
- riquadro con ID richiesta, IP e data e ora;
- tema scuro automatico;
- impaginazione a una colonna su telefono.

L'illustrazione è uno scudo che segue la verifica. Durante il controllo una barra lo scansiona e la spia lampeggia. A verifica riuscita compare la spunta e la spia diventa verde. In caso di blocco o errore compare la croce e la spia diventa rossa. Chi ha disattivato le animazioni nel sistema vede lo scudo fermo.

Nella pagina "Accesso negato" compare il pulsante **Scrivi all'amministratore**, che usa l'e-mail di contatto del forum e mette l'ID richiesta come oggetto.

- **Colore d'accento:** colore di pulsanti, bordi e scansione. Il predefinito `#22577a` è lo stesso di DB Guardian; il pulsante **Ripristina predefinito** lo rimette con un clic. Ricorda di salvare.
- **Logo:** indirizzo di un'immagine (`https://…` oppure `/percorso`), mostrata in alto a sinistra **sotto il nome del forum**, che resta sempre visibile. Se lo lasci vuoto, non compare nessun logo.
- **Logo ovale:** con *No* il logo mantiene la sua forma naturale. Con *Sì* i bordi vengono arrotondati: un logo quadrato diventa tondo, uno rettangolare diventa ovale.

### Registro IP e blocco temporaneo

- **Registra gli indirizzi IP:** attiva il registro.
- **Conserva gli IP per:** giorni di conservazione prima della cancellazione automatica via cron (0 = mai).
- **Frequenza della pulizia automatica:** ogni quanti minuti oppure ore il cron di phpBB esegue la pulizia. Il minimo è 5 minuti, il massimo 30 giorni; il predefinito è ogni 24 ore.
- **Tentativi falliti prima del blocco** e **Durata del blocco:** dopo N verifiche fallite di fila, l'IP vede "Accesso negato" per X minuti.
  - Una verifica superata azzera il conteggio.
  - Il conteggio riparte da zero anche quando passa più tempo della durata del blocco dall'ultimo fallimento. Così un IP sbloccato torna bloccato solo dopo altri N fallimenti, non al primo.
  - Chi ha già un pass valido non viene mai bloccato, anche se condivide l'IP con un bot.

### Anteprima

Sotto i tipi di verifica ci sono i link **Anteprima**, uno per ogni tipo. Aprono in una nuova scheda la pagina di verifica come la vedrebbe un ospite, anche se sei connesso e anche con la verifica spenta. In cima alla pagina compare un avviso che si tratta di un'anteprima, e le anteprime non finiscono nel registro IP.

### Nuova verifica per tutti

Annulla in un colpo tutti i pass già rilasciati: ogni visitatore **ospite** dovrà rifare la verifica alla pagina successiva. È utile durante un'ondata di bot.

Gli utenti connessi, amministratore compreso, ricevono subito un pass nuovo senza verifica, perché hanno già fatto il login. Per questo, se premi il pulsante, ti disconnetti e riapri il forum, **non** vedi la verifica. Per controllare la pagina usa l'anteprima, oppure una finestra in incognito.

## 5. Registro IP (ACP › Human Verify › Registro IP)

- In cima trovi i totali: IP registrati, verifiche mostrate, superate, fallite e bloccate.
- Puoi **cercare** un IP (anche parziale) e **filtrare** per ultimo esito.
- La tabella è **ordinabile** cliccando sulle intestazioni delle colonne.
- Per ogni riga vedi:
  - prima e ultima visita;
  - i contatori;
  - l'ultimo esito con il tipo di verifica;
  - lo user-agent completo, passando il mouse sopra;
  - il link Whois;
  - il distintivo **bloccato** se l'IP è in blocco temporaneo.
- Le azioni disponibili sono:
  - **Elimina:** cancella un singolo IP.
  - **Elimina selezionati:** cancella gli IP spuntati.
  - **Sblocca selezionati:** toglie il blocco temporaneo agli IP spuntati.
  - **Svuota il registro:** cancella tutti gli IP, con richiesta di conferma.
- Ogni azione viene annotata nel registro amministratori di phpBB.

## 6. Check-up (ACP › Human Verify › Check-up)

Il check-up esegue 16 test uno alla volta, con barra di avanzamento e percentuale reale, e segnala esito e dettagli di ciascuno:

1. Versione PHP
2. Versione phpBB
3. Configurazione, compreso l'avviso SEO se i motori di ricerca non possono passare
4. Funzioni crittografiche
5. Firma dei token, con rifiuto dei token manomessi e controllo del cookie
6. Calcolo proof-of-work, con stima dei tempi
7. Libreria GD
8. Captcha: genera l'immagine e verifica il codice
9. Puzzle: genera l'immagine e verifica la soluzione
10. Tabella del registro IP: scrittura, aggiornamento ed eliminazione di una riga di prova
11. Cache e uso singolo delle sfide
12. Cookie, con avviso se il forum è in HTTPS ma il cookie sicuro di phpBB è spento
13. Presenza di tutti i file
14. File di lingua italiano e inglese con le stesse chiavi
15. Elenco bot e validità della whitelist
16. Pulizia automatica del registro: se il cron di phpBB è fermo, indica il motivo. Distingue il forum impostato sul cron di sistema senza un cron di sistema attivo, caso tipico di una copia di prova su un hosting come Altervista, dal cron "web" che non parte. Inoltre controlla che il compito sia registrato nel cron di phpBB e che il cron giri davvero, guardando l'ultimo riordino delle sessioni. Se la pulizia non è mai partita, come subito dopo l'installazione, il check-up la esegue una volta per provarla.

### Strumenti cron (in fondo al Check-up)

- **Esegui ora la pulizia:** elimina subito dal registro gli IP più vecchi del limite impostato e mostra quanti ne ha eliminati e quando è stata eseguita l'ultima pulizia.
- **Esegui le attività cron:** esegue una alla volta, con barra di avanzamento e percentuale reale, tutte le attività pianificate pronte di phpBB e di tutte le estensioni, esattamente come farebbe il cron. Per ogni attività mostra esito e durata.
  - Usa lo stesso lucchetto del cron di phpBB, quindi non si sovrappone mai a un cron già in corso.
  - Le attività con parametri, come lo sfoltimento dei singoli forum, restano al cron.
  - È utile quando il cron è fermo.

## 7. Quando e dove compare la pagina di verifica

La pagina compare **al posto della pagina richiesta**, allo stesso indirizzo e senza reindirizzamenti, la prima volta che un visitatore apre una pagina qualsiasi del forum: indice, forum, argomenti, profili, ricerca, login, registrazione. Dopo la verifica la stessa pagina si ricarica e il visitatore la vede normalmente. Non la rivede finché il pass non scade, secondo "Ogni quanto chiedere la verifica".

Prima di mostrarla, l'estensione controlla queste condizioni in ordine:

1. Verifica spenta, pagina esclusa (vedi sotto) o IP nella whitelist: il visitatore entra.
2. User-agent nell'elenco dei bot, oppure user-agent vuoto: pagina "Accesso negato".
3. Motore di ricerca riconosciuto da phpBB: entra. Se è attivo il controllo DNS e il motore risulta falso, viene bloccato.
4. **Utente connesso** (con "Escludi gli utenti connessi" attivo): entra e riceve in silenzio il pass. Così dopo il logout, o se la sessione scade, non vede la verifica fino alla scadenza del pass.
5. Pass valido: entra. Il pass è controllato **prima** del blocco: su un IP condiviso, come gli operatori mobili o le reti aziendali, chi ha già superato la verifica non viene bloccato per colpa di un bot che usa lo stesso indirizzo.
6. IP bloccato per troppi tentativi falliti: pagina "Accesso negato".
7. In tutti gli altri casi: pagina di verifica.

## 8. Pagine mai bloccate

- l'ACP;
- il cron di phpBB (`cron.php` e `app.php/cron/...`);
- i feed (`app.php/feed`);
- `download/file.php` (allegati e avatar);
- la disconnessione (`ucp.php?mode=logout`);
- l'uso da riga di comando (`bin/phpbbcli.php`).

I moduli inviati in **POST** e le richieste **AJAX** hanno 24 ore di tolleranza dopo la scadenza del pass. Così un visitatore ospite che scrive un messaggio lungo non perde il testo se il pass scade proprio in quel momento.

## 9. Limiti da conoscere

- **Non è un firewall di rete.** Cloudflare ferma il traffico prima che arrivi al server. Qui PHP e phpBB partono comunque per ogni richiesta. La pagina di verifica è leggera, ma contro un attacco DDoS serve una protezione a livello di rete.
- **I browser automatizzati più evoluti** possono superare qualunque verifica di questo tipo. Il proof-of-work però rende molto costoso aprire migliaia di pagine, e la maggior parte degli scraper si ferma prima.
- **La casella da sola** è soprattutto un deterrente visivo: la protezione vera è il calcolo in background che la accompagna.
- **Gli IP sono dati personali.** Indica nella privacy policy del forum che vengono registrati e per quanto tempo.

## 10. File dell'estensione

```
salvocortesiano/humanverify/
├── composer.json, ext.php
├── acp/                 main_info.php, main_module.php
├── adm/style/           pagine ACP (impostazioni, registro, check-up, intestazione, crediti)
├── config/services.yml
├── controller/          acp_controller.php
├── core/                challenge.php   – token, proof-of-work, captcha, puzzle, cookie
│                        bot_detector.php – user-agent, whitelist, DNS motori di ricerca
│                        ip_log.php       – registro IP
│                        checkup.php      – test del Check-up
├── cron/task/           prune_log.php   – pulizia automatica del registro
├── event/               main_listener.php – intercettazione delle richieste
├── images/puzzle/       (facoltativa) le tue foto per il puzzle
├── language/it, en/     common.php, info_acp_humanverify.php
├── migrations/          install_v100.php
└── styles/all/          template e foglio di stile/script della pagina di verifica
```

## 11. Disinstallazione

1. Disattiva l'estensione in ACP › Gestione estensioni.
2. Usa **Elimina dati** per rimuovere la tabella `phpbb_hv_log` e tutte le impostazioni.
3. Rimuovi la cartella `ext/salvocortesiano/humanverify`.

## 12. Cronologia delle versioni

- **1.0.10**
  - Il pass valido viene controllato prima del blocco temporaneo: chi condivide l'IP con un bot (rete mobile, ufficio) non viene più bloccato.
  - Il contatore dei tentativi falliti riparte da zero dopo la durata del blocco, invece di bloccare di nuovo l'IP al primo errore.
- **1.0.9**
  - Il nome del forum resta sempre in alto e il logo compare sotto, invece di sostituirlo.
  - Nuova opzione *Logo ovale*.
  - Pulsante *Ripristina predefinito* per il colore d'accento.
- **1.0.8** – Corretta la pagina di verifica, che dalla 1.0.3 arrivava senza stile e senza script (percorso dei file, nome del sito e host mancanti). Il problema riguardava sia la verifica vera sia l'anteprima.
- **1.0.7** – Anteprima della pagina di verifica in ACP, per ogni tipo, utilizzabile anche da connesso e con la verifica spenta. Chiarito in ACP e nella guida l'effetto di "Nuova verifica per tutti" sugli utenti connessi.
- **1.0.6**
  - Nuova impostazione *Frequenza della pulizia automatica*, in minuti o ore.
  - Nel Check-up, pulsanti per eseguire subito la pulizia del registro e tutte le attività cron pronte del forum, con barra di avanzamento.
  - Il test del cron spiega se il forum aspetta un cron di sistema che non c'è.
- **1.0.5** – Corretto il campo *Colore d'accento* in ACP, che rifiutava sempre il colore. Corretta anche la sincronizzazione tra selettore e campo di testo.
- **1.0.4**
  - Gli utenti connessi ricevono in silenzio il pass: dopo il logout, o a sessione scaduta, non vedono più la verifica.
  - La disconnessione non viene mai interrotta dalla verifica.
- **1.0.3**
  - Nuova grafica della pagina di verifica e della pagina "Accesso negato", nello stile di DB Guardian, con illustrazione animata dello scudo e tema scuro.
  - Nuove impostazioni per colore d'accento e logo.
  - Pulsante "Scrivi all'amministratore" nella pagina di blocco.
- **1.0.2**
  - Corretto il test *Cookie* del Check-up, che si interrompeva per un accesso non consentito alle variabili del server.
  - Il test *Pulizia automatica* ora distingue un'estensione appena installata da un cron fermo.
  - Le descrizioni dei tipi di verifica in ACP vanno a capo dentro la propria scheda.
  - La stima del proof-of-work non mostra più "0 ms".
- **1.0.1** – Il registro amministratori salva i nomi tradotti dei moduli durante l'installazione.
- **1.0.0** – Prima versione.
