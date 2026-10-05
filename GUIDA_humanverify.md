# Human Verify – Verifica anti-bot per phpBB

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
