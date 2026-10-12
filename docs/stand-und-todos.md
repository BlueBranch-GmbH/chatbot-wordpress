# Stand und offene ToDos

Stand: 12.10.2026, Version **1.2.1** (Tag `1.2.1`). Anforderungen und Sicherheitsprüfung:
[ausbau-2026-10.md](ausbau-2026-10.md). Gemeinsamer Plan aller Repositories:
`chatbot-contao` → `docs/ausbau-2026-10.md`.

| Repository | Stand |
|---|---|
| `BlueBranch-GmbH/chatbot-wordpress` (dieses) | 1.2.1 |
| `BlueBranch-GmbH/chatbot-contao` | 1.4.0 |
| `spardorf-chatbot-api` | 0.0.56 |
| `spardorf-chatbot-frontend` | 0.1.29 |

## Was 1.2.0 und 1.2.1 enthalten

| Bereich | Stand | Getestet |
|---|---|---|
| 1.2.0: Nutzungsstufe zeigt den Verbrauch im laufenden Monat | fertig | phpcs, Syntax; **nicht im Browser** |
| 1.2.0: 429 mit `reason: monthly_quota` → Besucher lesen „später erneut versuchen“ | fertig | phpcs |
| 1.2.1: Herkunft und Token im `permission_callback` (`check_visitor_permission`, `check_origin_permission`), kein `__return_true` mehr; Ratenlimits bleiben im Callback (WordPress ruft Permission-Callbacks auch für den `Allow`-Header auf) | fertig | phpcs; **nicht live** |
| 1.2.1: `client.js` versteht eine JSON-Ablehnung vor dem Stream, erneuert abgelaufene Token einmal | fertig | Syntax; **nicht im Browser** |
| 1.2.1: `readme.txt` nennt Nutzung und Kosten des API-Dienstes | fertig | – |

Nach dem Update auf 1.2.1 den Seiten-Cache leeren: Zwischengespeicherte Seiten laden sonst das
alte Skript, und eine Ablehnung zeigt dort nur die allgemeine Fehlermeldung.

## Einreichung bei wordpress.org

Eingereicht am 16.09.2026 als 1.0.0, Konto **bluebranch**, Slug `bluebranch-chatbot`. Die Mails
gehen an **admin@bluebranch.de**. Automatische Vorprüfung vom 18.09.2026 („Review in Progress:
BlueBranch Chatbot“) bemängelte `permission_callback => '__return_true'` und mitgelieferte
Übersetzungsdateien – beides mit 1.2.1 behoben. Ohne Antwort im Thread wird die Einreichung
drei Monate nach der Vorprüfung abgelehnt (Mitte Dezember 2026).

Das Paket für wordpress.org wird als **1.0.0** hochgeladen (erste Veröffentlichung im
Verzeichnis), gebaut aus dem Tag `1.2.1`:

1. `git archive 1.2.1`, darin `composer install --no-dev --optimize-autoloader`
2. Entfernen: `docs/`, `README.md`, `phpcs.xml.dist`, versteckte Dateien,
   `languages/*.po`, `*.mo`, `*.json` (nur die `.pot` bleibt)
3. Plugin-Header, `VERSION` und `Stable tag` auf 1.0.0; Changelog und Upgrade Notice nur
   „1.0.0 – First release“
4. Hochladen unter wordpress.org/plugins/developers/add, danach im Mail-Thread antworten

Offen: Upload des Pakets und Antwort im Thread.

## Was 1.1.0 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| Fragen/Antworten speichern (eigene Tabelle, maskiert, Aufbewahrung) | fertig | Mock-API, curl |
| Feedback 👍/👎 mit Kommentar (global schaltbar) | fertig | Browser |
| „Fragen & Feedback“ mit Suche, Filter, Bulk-Löschen, CSV-Export | fertig | Admin-Seiten (HTTP 200), CSV |
| Chat-Export `.txt`/`.vtt` mit Beginn/Ende jeder Antwort, Uhrzeit je Cue | fertig | Node (Ausgabe geprüft) |
| Verlauf je Website (nicht mehr je Element-ID) – übersteht Seitenwechsel; Ablauf nach 24 h | fertig | Browser |
| Zusatzinhalte (Text, TXT/MD/CSV/PDF/DOCX/ODT/HTML), lokal zu Text, ohne URL | fertig | TXT/DOCX/PDF-Auszug |
| Gelöschte Mediathek-Datei → sofort aus dem Index; einzeln gelöscht → deaktiviert | fertig | Syntax/phpcs, nicht live |
| Browser → WordPress per POST (`fetch`-Stream statt EventSource) | fertig | curl + Browser |
| Sicherheitsreview 06.10.2026 | behoben | siehe ausbau-2026-10.md |

### Release-Paket

`vendor/` steht in `.gitignore`. Das ZIP für die Installation muss mit
`composer install --no-dev` gebaut werden (enthält dann `smalot/pdfparser`). Ohne die
Bibliothek werden PDFs mit Hinweis abgelehnt; alle anderen Formate funktionieren.

## Offene ToDos

### Sicherheit und Datenschutz

- [ ] **WordPress → API per GET.** Frage und Verlauf stehen in den Zugriffslogs der API. Braucht
      eine POST-Variante der Stream-Routen in der API, dann `Api_Client::stream()` umstellen.
- [ ] **Autostart über `?s=`.** Ein Link mit Suchbegriff löst auf der eigenen Domain sofort eine
      KI-Antwort aus (Content Spoofing, Kontingent). Option „Antwort erst nach Klick“ ergänzen.
- [ ] **Titel von Zusatzinhalten im Stream** sichtbar (Netzwerk-Tab), da die SSE-Frames
      unverändert durchgereicht werden.
- [ ] **Gleichzeitige Streams** je Client nicht begrenzt (nur Anzahl je Minute); jeder Stream
      hält bis zu 180 s einen PHP-Worker.
- [ ] **Hinter Proxy/CDN** muss der Filter `bluebranch_chatbot_client_ip` die echte Besucher-IP
      liefern, sonst teilen sich alle ein Ratenlimit. In der Anleitung dokumentieren.

### Funktion

- [ ] **Datei-Abgleich nur täglich** (Contao: alle 15 Minuten). Geänderte Mediathek-Dateien
      werden spätestens nach einem Tag oder per „Neu übertragen“ übernommen.
- [ ] **`.md`-Uploads**: `wp_check_filetype_and_ext` meldet für Markdown je nach Server
      `text/plain` – prüfen, ob der Upload überall durchgeht.
- [ ] **Nicht live getestet nach den letzten Änderungen:** Löschen einer Mediathek-Datei mit
      Zusatzinhalt, VTT im Player.
- [ ] **Zeitgesteuerte Inhalte:** WordPress kennt kein „Anzeigen bis“; geplante Beiträge werden
      über `transition_post_status` erfasst. Plugins mit Ablaufdatum (z. B. Post Expirator) sind
      nicht berücksichtigt.

### Entwicklung

- [ ] Keine automatisierten Tests (phpcs ja, PHPUnit nein). Kandidaten: `Answer_Log::mask()`,
      `Text_Extractor`, Rate-Limiter, CSV-Ausgabe, `client.js`-Stream-Parser.
- [ ] Lokale Umgebung: Im neu angelegten Lando-Container fehlt `wp-cli` – `lando rebuild`.
