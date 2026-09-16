# BlueBranch Chatbot – KI-Chatbot und KI-Suche für WordPress (DE)

Beantworte Besucherfragen direkt auf deiner WordPress-Website – mit einer KI, die ausschließlich
deine eigenen Inhalte kennt.

Die Erweiterung übergibt deine Beiträge und Seiten an die Chatbot-API, die daraus eine
Vektor-Wissensbasis aufbaut. Aus genau diesem Bestand werden Fragen beantwortet – als
aufklappbares Chat-Widget, als Fragefeld und als zusammenfassende Antwort über der Trefferliste
der WordPress-Suche.

Der API-Schlüssel bleibt dabei auf dem Server: Der Browser spricht ausschließlich mit WordPress,
WordPress spricht mit der API.

> Schwesterprojekt: [chatbot-contao](https://github.com/BlueBranch-GmbH/chatbot-contao) – dieselbe
> Wissensbasis, dieselbe API, dieselben CSS-Klassen. Eine Gestaltung, die für die eine Fassung
> geschrieben wurde, passt auch auf die andere.

## So funktioniert die WordPress-Integration

1. Erweiterung installieren und aktivieren
2. Auf [chatbot.bluebranch.de](https://chatbot.bluebranch.de) registrieren und einen API-Key erstellen
3. Den Schlüssel unter *BlueBranch Chatbot → Einstellungen* hinterlegen
4. Einmal *BlueBranch Chatbot → Inhalte trainieren* laufen lassen
5. *Chat-Button auf allen Seiten anzeigen* einschalten oder Block bzw. Shortcode setzen

Fertig!

## Voraussetzungen

- PHP 7.4 oder neuer
- WordPress 6.0 oder neuer
- Ein Chatbot-Zugang mit API-Schlüssel von [chatbot.bluebranch.de](https://chatbot.bluebranch.de)

## Was anders ist als unter Contao

Contao füllt seine Wissensbasis über den Suchindex: Der Crawler läuft, ruft für jede Seite den
`indexPage`-Hook auf, und die Erweiterung schickt den fertig gerenderten Inhalt an die API.

WordPress hat keinen Crawler. Deshalb läuft es hier andersherum:

| | Contao | WordPress |
|---|---|---|
| Was gecrawlt wird | Contao-Crawler über die Seitenstruktur | die XML-Sitemap der Website |
| Neue Inhalte | beim Suchindex-Lauf | direkt nach dem Speichern, über WP-Cron |
| Bestehende Inhalte | beim Suchindex-Lauf | einmalig über *Inhalte trainieren* |
| Rendering | HTML des Crawlers | HTML des Crawlers, ersatzweise `the_content` |
| API-Schlüssel | je Startpunkt | je Website (bei Multisite je Blog) |
| Ausschluss | Feld an `tl_page` | Checkbox im Beitrags-Editor |
| Module | Frontend-Module | Blöcke und Shortcodes |
| Routen | Contao-Routen | REST-Routen unter `bluebranch-chatbot/v1` |

## Wie die Inhalte eingesammelt werden

Die Erweiterung ruft die Seiten der Website **über HTTP auf**, so wie ein Besucher es täte. Was
trainiert wird, ist damit das, was auf der Seite steht — einschließlich allem, was das Theme
außerhalb von `post_content` rendert: ACF-Felder, Abschnitte eines Page-Builders, Titel- und
Meta-Blöcke eines Block-Themes. Nichts davon steht in `post_content`, alles davon steht auf der
Seite.

**Die Sitemap gibt die Grenze vor.** Gecrawlt wird, was dort steht, und sonst nichts — es werden
keine Links aus den Seiten heraus verfolgt. Der Crawl kann sich also nicht in einer
Filterkombination oder einem Kalender verlaufen, der bis in alle Ewigkeit neue URLs erzeugt. Die
Adresse der Sitemap findet die Erweiterung selbst: Zuerst wird `robots.txt` gelesen, wo jedes
SEO-Plugin seine eigene Sitemap ankündigt; erst danach werden die üblichen Dateinamen probiert.

Jede gefundene URL wird auf den Beitrag zurückgeführt, zu dem sie gehört. Das ist keine
Förmlichkeit: Die Beitrags-ID ist es, unter der ein Eintrag abgelegt wird, an der die
Ausschluss-Checkbox hängt, und über die der Eintrag wieder zurückgezogen werden kann, wenn der
Beitrag verschwindet. Eine URL ohne Beitrag dahinter — ein Kategorie-Archiv, eine Autorenseite —
wird deshalb **gezählt und genannt, aber nicht trainiert**; die Zahl steht unter *Inhalte
trainieren*, damit niemand rätseln muss, wo der Rest geblieben ist.

**Schlägt der Crawl fehl, wird gerendert.** Nicht jeder Server darf sich selbst aufrufen —
WordPress hat für genau diesen Fall eine eigene Prüfung in der Website-Zustand-Ansicht. Statt in
so einer Umgebung stillschweigend nichts zu trainieren, fällt die Erweiterung auf den
`the_content`-Filter zurück. Unter *Inhalte trainieren* steht vorab, ob der Aufruf funktioniert.

## Header, Footer und alles andere, was auf jeder Seite steht

Eine gecrawlte Seite besteht zum guten Teil aus Dingen, die mit ihrem Inhalt nichts zu tun haben.
Würde man sie mittrainieren, zitierte der Chatbot irgendwann das Cookie-Banner als Antwort. Drei
Durchgänge räumen sie weg, jeder fängt ab, was der vorige nicht kann:

**1. Die Website markiert sich selbst.** Der Crawler schickt einen signierten Header mit. Eine
Anfrage mit diesem Header bekommt ihre Möblierung in ein Markierungselement gewickelt — und zwar
von WordPress selbst, das ja weiß, dass dieser Block ein Header-Template-Part ist, dass jenes
Markup aus `wp_nav_menu()` stammt, dass das dort eine Sidebar ist. Von außen ist das Raterei, die
bei jedem Theme anders schiefgeht; von innen ist es keine.

Template-Parts werden dabei über ihren *Bereich* erkannt, nicht über ihren Namen: Ein Theme darf
einen Inhaltsabschnitt in einem Template-Part unterbringen, und den zu entfernen nähme echten
Inhalt mit.

**2. Struktur.** Ein `<main>` grenzt die Seite in einem Schritt ein; sonst fliegt heraus, was
über `role` oder Tag als Möblierung erkennbar ist. Ein `<header>` **innerhalb** eines `<article>`
bleibt dabei ausdrücklich stehen — das ist der Entry-Header, und dort steht die Überschrift. Ein
`<header>` pauschal zu entfernen, löschte auf der Hälfte aller Themes die Titel.

**3. Wiederholung.** Was auf fast allen Seiten steht, ist nicht das, worum es auf einer einzelnen
geht. Zu Beginn eines Laufs werden ein paar Seiten — quer über die Liste verteilt, nicht die
ersten sechs — abgetastet und jeder Textblock gehasht; was auf mindestens 60 % davon auftaucht,
gilt als Möblierung.

Das ist der Durchgang mit Zähnen: Er findet, was keine Selektorliste vorhersehen kann, weil es auf
jeder Website anders aussieht — ein Cookie-Hinweis, eine Breadcrumb-Leiste, ein Newsletter-Kasten
unter jedem Artikel. Entfernt wird in der Reihenfolge der Sicherheit, und es wird aufgehört,
sobald zu wenig Text übrig bliebe. Eine Kontaktseite, deren Inhalt tatsächlich die Adresse aus dem
Footer ist, behält sie also.

Unter *Inhalte trainieren → Eine Seite prüfen* lässt sich für jede Adresse nachsehen, was
tatsächlich übrig bleibt. Ob die Möblierung wirklich verschwunden ist, sollte niemand glauben
müssen — und erst recht nicht daran merken, dass der Chatbot das Impressum zitiert.

## Wie der Bestand aktuell bleibt

Es wird nicht einmal alles geschickt und später alles gelöscht. Jede Änderung wirkt für sich:

| Was passiert | Was die Erweiterung tut |
|---|---|
| Beitrag veröffentlicht | wird gecrawlt und übergeben |
| Beitrag geändert | wird neu gecrawlt und überschrieben |
| Beitrag auf Entwurf gesetzt | wird zurückgezogen |
| Beitrag in den Papierkorb | wird zurückgezogen |
| Beitrag endgültig gelöscht | wird sofort zurückgezogen |
| Ausschluss-Checkbox gesetzt | der Beitrag und sein ganzer Zweig werden zurückgezogen |
| Auf `noindex` gesetzt | beim nächsten Bereinigungslauf zurückgezogen |
| Aus der Sitemap verschwunden | beim nächsten Bereinigungslauf zurückgezogen |

Der Lauf über die Sitemap ist deshalb billig zu wiederholen: **Zu jedem Beitrag wird ein Hash
dessen gespeichert, was zuletzt übergeben wurde.** Hat sich daran nichts geändert, wird auch nichts
gesendet. Ein zweiter Lauf kostet einen API-Aufruf je *geänderter* Seite, nicht je Seite. Auf der
Trainingsseite steht hinterher, wie viele Seiten trainiert, unverändert, zurückgezogen oder
übersprungen wurden.

Wer den Bestand wirklich einmal komplett neu schreiben will, hakt *Alles erneut senden* an.

Die URL einer Seite darf sich dabei ändern: Abgelegt wird unter der Beitrags-ID, nicht unter der
Adresse. Ein geänderter Permalink aktualisiert den vorhandenen Eintrag, statt einen zweiten
anzulegen.

## Einrichtung

### 1. API-Schlüssel hinterlegen

*BlueBranch Chatbot → Einstellungen → API-Schlüssel*.

Ein hinterlegter Schlüssel wird **nie ins Formular geschrieben**. Im Feld stehen nur Punkte – im
Quelltext der Seite, im Browserverlauf und in jedem Zwischenspeicher also ebenfalls.

| Eingabe | Wirkung |
|---|---|
| Punkte stehen lassen | Der Schlüssel bleibt |
| Feld leeren | Der Schlüssel wird gelöscht |
| Etwas anderes eintragen | Wird als neuer Schlüssel übernommen |

Erkannt wird nicht nur die exakte Zeichenfolge: Jede Eingabe, die ausschließlich aus
Maskierungszeichen besteht – Punkt, Sternchen, Bullet –, gilt als „unverändert". Würde ein
Browser die Punkte anders zurückschicken, entstünde sonst ein Schlüssel aus Aufzählungspunkten,
und der Chatbot des Kunden schwiege ab dem nächsten Speichern.

### 2. Inhalte trainieren

*BlueBranch Chatbot → Inhalte trainieren* geht jeden in Frage kommenden Beitrag durch, in
Stapeln von wenigen Stück. Danach übernimmt das Speichern: Jeder veröffentlichte oder geänderte
Beitrag wird kurz darauf über WP-Cron trainiert.

Übergeben werden ausschließlich Inhalte, die

- veröffentlicht und öffentlich sichtbar sind,
- kein Passwort tragen,
- zu einem der gewählten Inhaltstypen gehören,
- nicht über Yoast SEO, Rank Math oder SEOPress auf `noindex` stehen,
- und nicht manuell ausgeschlossen wurden.

> **WP-Cron läuft nur bei Seitenaufrufen.** Auf einer ruhigen Entwicklungsinstallation passiert
> deshalb erst einmal nichts. `wp cron event run --due-now` stößt die anstehenden Läufe sofort an.

### 3. Module einbinden

| Block | Shortcode | Zweck |
|---|---|---|
| *Chatbot Widget* | `[bluebranch_chatbot_widget]` | Aufklappbarer Chat-Button |
| *Chatbot Suchantwort* | `[bluebranch_chatbot_search]` | Fragefeld mit Antwort darunter — über einer Trefferliste stattdessen die Antwort zum gesuchten Begriff |

Es gab bis 1.0 zwei getrennte Module dafür, *Chatbot Frage* und *Chatbot Suchantwort*. Beide
schickten dieselbe Anfrage an dieselbe Route und stellten das Ergebnis gleich dar; verschieden war
nur, woher die Frage kam. Das bedeutete zwei Blöcke, zwei Shortcodes und zwei Templates für ein
Verhalten — und Redakteure, die wissen mussten, welches von zwei fast gleichen Dingen sie greifen.
Jetzt kann die Frage aus beidem kommen: Auf einer Seite eingebunden bringt die Suchantwort ihr
eigenes Feld mit, über einer Trefferliste nutzt sie das Feld des Themes und den Begriff aus der
URL.

Der Chat-Button lässt sich zusätzlich über *Chat-Button auf allen Seiten anzeigen* global
einschalten; die KI-Antwort über der Suche über *Antwort über den Suchergebnissen*.

Die Blöcke haben bewusst keine eigenen Einstellungen – sie folgen den globalen Vorgaben. Wer für
eine einzelne Seite etwas anderes braucht, nimmt den Shortcode:

```
[bluebranch_chatbot_widget position="bottom-left" color="#c8102e" name="Hilfe"
    suggestions="Was macht ihr?|Wo sitzt ihr?" hide_disclaimer="yes"]

[bluebranch_chatbot_search questions="Wie erreiche ich euch?|Was kostet das?"
    button="Los" placeholder="Was möchten Sie wissen?"]

[bluebranch_chatbot_search form="no" param="s"]
```

`form="no"` lässt das eigene Feld weg — gedacht für Suchergebnisseiten, auf denen das Theme schon
eines hat. Genau so wird die Suchantwort auch automatisch eingehängt. `autostart="no"` verhindert,
dass ein Begriff aus der URL sofort beantwortet wird.

### Antwort abbrechen

Während eine Antwort läuft, ist derselbe Knopf der Stopp-Knopf — wie man es von ChatGPT kennt. Er
wird dabei **nicht** deaktiviert: Eine laufende Antwort, die jemand nicht mehr will, ist der eine
Moment, in dem ein Knopf am dringendsten gebraucht wird; ihn ausgegraut zu zeigen, nähme ihn genau
dann weg.

Er trägt dann die Klasse `chatbot-submit--stop` (im Widget `chatbot-widget__send--stop`) und
wechselt, sofern das Standard-Styling geladen ist, auf einen neutralen Grauton. Die Akzentfarbe
bleibt dem Senden vorbehalten — sie heißt „das hier ist zu drücken", und für das Abbrechen stimmt
das nicht.

**Was schon gelesen wurde, bleibt stehen.** Nur wenn noch gar nichts angekommen war, tritt ein
kurzer Hinweis an die Stelle, damit die Frage nicht ohne alles dasteht.

### Beschriftung ändern

Unter *Einstellungen → Beschriftung des Fragefelds* lassen sich Button und Platzhalter des
Fragefelds frei texten. Leer gelassen, stehen dort die übersetzten Vorgaben. Der Shortcode kennt
dafür zusätzlich `button="…"`, `stop="…"` und `placeholder="…"`, wenn eine einzelne Seite etwas
anderes brauchen soll.

### Vorschlagsfragen als Pills

Die unter *Fragen-Vorschläge* hinterlegten Texte erscheinen als anklickbare Pills unter dem
Fragefeld — ein Klick trägt die Frage ins Feld ein und schickt sie ab. Sie stehen dort unabhängig
davon, ob die getippte Animation läuft, und sind damit der verlässlichere Weg, Beispielfragen zu
zeigen.

Das Chat-Widget benutzt dieselbe Liste für seine eigenen Pills.

### Getippte Fragen

Die unter *Getippte Fragen* hinterlegten Texte werden im Frontend nacheinander Zeichen für
Zeichen als Platzhalter ins Eingabefeld geschrieben, gelöscht und durch den nächsten ersetzt.

Auf einer Suchergebnisseite sucht sich die Animation das Suchfeld des Themes (`input[name="s"]`),
sodass sie auch dort funktioniert, wo das Formular nicht von dieser Erweiterung stammt.

Die Animation ruht, sobald jemand das Feld anklickt oder etwas hineinschreibt, im Hintergrund-Tab
läuft sie gar nicht erst, und wer *reduzierte Bewegung* eingestellt hat, sieht statt der
Animation die erste Frage unbewegt stehen.

> **Es wird nichts getippt?** Dann ist mit hoher Wahrscheinlichkeit im Betriebssystem
> *Bewegung reduzieren* aktiv — unter macOS in *Systemeinstellungen → Bedienungshilfen → Anzeige*.
> Das ist so gewollt: Eine Animation, die jemand ausdrücklich abbestellt hat, gehört nicht
> trotzdem ausgeführt. Die Pills zeigen dieselben Fragen ohne jede Bewegung.

## Antwortlänge

Die API kennt zwei Modi, und die Erweiterung benutzt beide — jedes Modul den passenden:

| | Chat-Widget | Suchantwort |
|---|---|---|
| Route | `generate/chat/stream` | `generate/chatbot/stream` |
| Kontext-Abschnitte | 4 | 8 |
| Antwortlänge | 250 Tokens | 800 Tokens |
| Suchdurchgänge | 1 (Similarity) | 3 (MMR, Similarity, Titel) |

Ein Chat-Fenster wird im Dialog benutzt, dort zählt die Zeit bis zur fertigen Antwort; die
Suchantwort steht einmal über der Trefferliste und darf ausführlich sein.

**Diese Zahlen sind in den Einstellungen bewusst nicht zu finden.** Sie stammen aus
`chat-ai.config.ts` der API, wo die Abwägung mit Kenntnis von Modell und Hardware getroffen und
begründet ist. Ein Feld in wp-admin hieße, diese Entscheidung von jemandem überstimmen zu lassen,
der diese Kenntnis nicht hat — und es kauft meist nur Wartezeit: Auf einer Wissensbasis, die zu
einer Frage nicht mehr hergibt, erzeugt ein größeres Budget dieselbe Antwort langsamer.

> Gemessen an einer Installation mit sieben kurzen Beiträgen: 800 Tokens ergaben 957 Zeichen,
> 2000 Tokens ergaben 983. Was eine Antwort begrenzt, ist fast immer der Inhalt dahinter.

Für die Installation, die wirklich abweicht, gibt es einen Filter statt eines Feldes — absichtlich
PHP, denn wer diese Entscheidung treffen sollte, kann auch eine Zeile davon schreiben:

```php
add_filter( 'bluebranch_chatbot_answer_tokens', function ( $tokens, $mode ) {
    return 'search' === $mode ? 1500 : $tokens;   // 32 bis 2000, 0 lässt die Vorgabe der API
}, 10, 2 );
```

## Seiten von den KI-Antworten ausschließen

Im Beitrags-Editor steht rechts die Box **BlueBranch Chatbot** mit dem Feld *Aus den KI-Antworten
ausschließen*. Dort steht außerdem, ob der Beitrag gerade in der Wissensbasis ist und seit wann.

**Bei hierarchischen Inhaltstypen vererbt sich die Einstellung auf alle Unterseiten.** Wer eine
Rubrik abhakt, meint den ganzen Zweig. Beim Speichern werden die betroffenen Seiten sofort aus der
Wissensbasis entfernt, nicht erst beim nächsten Bereinigungslauf.

## Bereiche vom Training ausnehmen

```
[bluebranch_chatbot_exclude]
Dieser Abschnitt bleibt auf der Seite, wird aber nie trainiert.
[/bluebranch_chatbot_exclude]
```

Gedacht für Wiederholungen: Ein Hinweis, der in jedem Beitrag steht, wird sonst so oft trainiert,
wie es Beiträge gibt, und fängt an, als Antwort auf unverwandte Fragen aufzutauchen.

## Automatische Bereinigung

Inhalte, die nicht mehr veröffentlicht, ausgeschlossen oder auf `noindex` gesetzt sind, gehören
nicht in den KI-Index. Das meiste wird schon beim Speichern und beim Statuswechsel erledigt; der
Bereinigungslauf fängt die Fälle ab, für die nichts feuert – ein Inhaltstyp, der aus den
Einstellungen genommen wurde, ein `noindex`, das ein SEO-Plugin ohne Beitragsänderung gesetzt hat,
ein API-Aufruf, der scheiterte, während der Server offline war.

Das Intervall ist wählbar: stündlich, alle 6 Stunden, täglich (Vorgabe) oder wöchentlich. Der Lauf
hängt am Cron-System von WordPress – ein Server-Cronjob ist nicht nötig.

Betrachtet werden dabei nur Beiträge, die den Merker „trainiert" tragen. Einen Eintrag zu
widerrufen, der nie gesendet wurde, kostet einen API-Aufruf und bringt nichts – dieser Merker ist
es, der eine Website mit tausenden Entwürfen davor bewahrt, bei jedem Lauf tausende sinnlose
Anfragen zu stellen.

## Nutzungsstufe

Oben auf der Übersicht und in den Einstellungen steht, in welcher Stufe der hinterlegte Zugang
läuft — *Free*, *Pro* oder *Expert* — samt Anfragekontingent.

Die Zahlen stehen bewusst **nicht** in dieser Erweiterung. Sie kommen zur Laufzeit von der API.
Schickt diese einen fertigen Hinweistext mit, wird genau der angezeigt; ändert sich also
Kontingent, Wortlaut oder Anschrift, genügt ein Deployment der API und die Anzeige folgt, ohne
dass eine neue Fassung der Erweiterung ausgeliefert werden müsste. Kommt kein Hinweistext, wird
der Satz aus Stufenname und Kontingent gebildet — beides ebenfalls von der API.

## Trainierte Inhalte einsehen

*BlueBranch Chatbot → Trainierte Inhalte* zeigt, was die Wissensbasis tatsächlich enthält – mit
Titel, URL, Anzahl der Chunks, Sprache und Trainingsdatum. Einzelne Einträge oder der gesamte
Bestand lassen sich dort löschen.

Die Zeilen kommen von der API, nicht aus WordPress. Das ist der Punkt: Was zählt, ist, was die
Wissensbasis glaubt, und das ist nicht immer, was diese Website geschickt hätte. Eine vor Monaten
gelöschte Seite, die noch Fragen beantwortet, ist genau die Art von Sache, für die es diese
Übersicht gibt.

Über das eingebaute Test-Feld kannst du dem Chatbot direkt eine Frage stellen – über genau
dieselbe Route, die auch ein Besucher benutzt.

## Wie die Anfragen laufen

Der Browser ruft ausschließlich WordPress-Routen auf, die ihrerseits die API ansprechen:

| Route | Aufgabe |
|---|---|
| `GET /wp-json/bluebranch-chatbot/v1/token` | Holt ein kurzlebiges Token |
| `GET …/v1/chat/stream` | Antwort im Chat-Modus (kurz, schnell) |
| `GET …/v1/generate/stream` | Antwort im Such-Modus (ausführlich) |
| `POST …/v1/generate/search` | Antwort ohne Streaming |

Die Antworten kommen als Server-Sent Events zurück: zuerst ein `sources`-Ereignis mit den
verwendeten Seiten, danach die Antwort in Stücken, zum Schluss ein `end`-Ereignis.

### Warum kein Nonce

Für die Antwort-Routen wäre ein WordPress-Nonce die naheliegende Wahl und die falsche, aus zwei
Gründen.

Ein Nonce hängt am Benutzer, für den es erzeugt wurde. Die Antwort-Routen sind öffentlich – sie
müssen für nicht angemeldete Besucher funktionieren –, weshalb der REST-Server den aktuellen
Benutzer für sie auf 0 setzt. Ein Token, das der Browser einer Administratorin im angemeldeten
Zustand geholt hat, würde dann gegen einen anderen Benutzer geprüft und nie aufgehen; das
Test-Feld im Backend würde jede Frage ablehnen.

WordPress prüft `_wpnonce` außerdem selbst und beantwortet ein ungültiges mit einem JSON-Fehler,
bevor die Route überhaupt erreicht ist. Bei einem Event-Stream kommt das als bloßer
Verbindungsabbruch an: Der Besucher erfährt nichts, und das Skript kann ein abgelaufenes Token –
das es stillschweigend ersetzen sollte – nicht von einem echten Fehler unterscheiden.

Deshalb steht dort, was es tatsächlich ist: ein signierter Merker mit Laufzeit, auf dem
`nonce`-Salt der Website, an keinen Benutzer gebunden. Die eigentliche Grenze zieht der
Ratenbegrenzer – 20 Antworten je Minute und Client, über `bluebranch_chatbot_rate_limit`
einstellbar. Von der IP-Adresse wird dabei nur ein Hash gespeichert, nie die Adresse selbst.

### Streaming

Die WordPress-HTTP-API puffert eine Antwort vollständig, bevor sie sie herausgibt – für einen
Stream unbrauchbar. Statt an ihr vorbei zu arbeiten, nutzt diese Erweiterung den dafür
vorgesehenen Haken: `http_api_curl` feuert, nachdem `WP_Http_Curl` seine eigene
Schreibfunktion gesetzt hat und bevor `curl_exec()` läuft. Dort wird `CURLOPT_WRITEFUNCTION`
umgebogen, und jedes Stück geht direkt an den Browser.

Der Status wird beim ersten Stück geprüft, nicht später: Danach hat der Browser längst eine 200
mit `text/event-stream` bekommen und sähe nur noch einen Abriss ohne erkennbaren Grund. Bei einer
Ablehnung geht der ausführliche Grund ins Log – bei 429 nennt die API die Nutzungsstufe des
Betreibers samt Kontaktadresse, und das gehört nicht vor die Augen der Besucher.

Steht kein cURL zur Verfügung, holt die Erweiterung die Antwort in einem Stück und gibt sie als
ein einziges Ereignis weiter. Der Chat funktioniert, nur der Tippeffekt fehlt.

Hinter einem Reverse Proxy muss dieser das Puffern für diese Routen abschalten
(`proxy_buffering off` bei nginx) – der `X-Accel-Buffering: no`-Header wird bereits gesendet.

## Gestaltung anpassen

Alle Farben und Größen liegen als CSS-Custom-Properties auf `.chatbot-widget`, ein eigenes
Stylesheet kann das Widget also ohne Eingriff in die Erweiterung umfärben:

```css
.chatbot-widget {
    --chatbot-widget-accent: #c8102e;
    --chatbot-widget-radius: 4px;
    --chatbot-widget-font: "Inter", sans-serif;
}

.chatbot-ask-container,
.chatbot-generate-search-container {
    --chatbot-answer-accent: #c8102e;
    --chatbot-answer-radius: 4px;
}
```

Rahmen und Flächen sind bewusst mittleres Grau mit Alpha und der ruhigere Text nimmt
`currentColor`: So liest sich dasselbe CSS auf hellen wie auf dunklen Themes, ohne dass die
Erweiterung raten müsste, auf welchem sie gelandet ist.

Wer das mitgelieferte CSS gar nicht will, schaltet unter *Einstellungen → Gestaltung* das
Standard-Styling ab. Das gilt dann für alle drei Module gleichermaßen — Chat-Button, Fragefeld und
Suchantwort.

Die drei Templates lassen sich überschreiben, indem man sie aus `templates/` in einen Ordner
`bluebranch-chatbot/` im Theme kopiert:

```
wp-content/themes/dein-theme/bluebranch-chatbot/widget.php
wp-content/themes/dein-theme/bluebranch-chatbot/ask.php
wp-content/themes/dein-theme/bluebranch-chatbot/search.php
```

## Hooks für Entwickler

| Hook | Typ | Wirkung |
|---|---|---|
| `bluebranch_chatbot_api_base` | Filter | Adresse der API, etwa für eine Testinstanz |
| `bluebranch_chatbot_sitemap_urls` | Filter | Die aus der Sitemap gelesenen Adressen |
| `bluebranch_chatbot_crawl_url` | Filter | Die Adresse, die der Crawler tatsächlich aufruft |
| `bluebranch_chatbot_post_types` | Filter | Welche Inhaltstypen trainiert werden |
| `bluebranch_chatbot_is_eligible` | Filter | Ob ein einzelner Beitrag als Quelle dient |
| `bluebranch_chatbot_rendered_html` | Filter | Gerendertes Markup vor der Markdown-Wandlung |
| `bluebranch_chatbot_post_payload` | Filter | Die fertige Nutzlast vor dem Senden |
| `bluebranch_chatbot_post_language` | Filter | Sprache je Beitrag, für mehrsprachige Websites |
| `bluebranch_chatbot_request_language` | Filter | Sprachcode einer Antwort-Anfrage |
| `bluebranch_chatbot_rate_limit` | Filter | Antworten je Minute und Client |
| `bluebranch_chatbot_max_prompt_length` | Filter | Maximale Länge einer Frage |
| `bluebranch_chatbot_show_auto_widget` | Filter | Ob der globale Chat-Button auf diesem Request erscheint |

Ein Page-Builder, der sein Layout außerhalb von `post_content` ablegt, hängt sich an
`bluebranch_chatbot_rendered_html` und liefert seine eigene Ausgabe.

## Datenschutz und Hosting

- **Hosting in Deutschland.** Server und Datenverarbeitung liegen ausschließlich in Deutschland.
- **Eigenes KI-Modell.** Deine Inhalte gehen nicht an OpenAI, Anthropic oder andere Drittanbieter.
- **Kein Training mit deinen Daten.** Weder Inhalte noch Chatverläufe werden zum Trainieren von
  Modellen verwendet.
- **Auftragsverarbeitungsvertrag.** Ein AVV nach Art. 28 DSGVO ist möglich – auf Anfrage per
  E-Mail an lb@bluebranch.de.
- **Kein Tracking.** Die Erweiterung setzt keine eigenen Cookies und speichert keine
  IP-Adressen. Die Anfragen an die API stellt der Server, nicht der Browser des Besuchers.
- **Der API-Schlüssel bleibt auf dem Server.** Der Browser bekommt ihn zu keinem Zeitpunkt zu sehen.

Welche Daten wann an die API gehen, steht vollständig im Abschnitt *External services* in der
[readme.txt](readme.txt).

## Deinstallation

Das Deaktivieren lässt Einstellungen und Wissensbasis in Ruhe – ein Plugin wird routinemäßig
abgeschaltet, um etwas zu prüfen, und eine Deaktivierung, die den Bestand eines Kunden löscht,
wäre nicht wiederherstellbar.

Beim **Löschen** entfernt `uninstall.php` alle Optionen, Transients, Cron-Ereignisse und
Beitrags-Metadaten. Die trainierten Inhalte bei der API bleiben bestehen: Sie gehören zum Zugang,
nicht zu dieser Installation. Wer sie loswerden will, benutzt vorher *Alle Inhalte löschen*.

## Entwicklung

```bash
composer install
composer lint   # PHP_CodeSniffer gegen die WordPress Coding Standards
composer fix    # behebt, was sich automatisch beheben lässt
```

Die Erweiterung ist gegen `WordPress` (Core, Docs und Extra) sowie `PHPCompatibilityWP` ab
PHP 7.4 fehlerfrei. Es gibt keinen Build-Schritt: Was im Repository liegt, ist das, was läuft.

---

# BlueBranch Chatbot – AI chat and AI search for WordPress (EN)

Answer visitor questions directly on your WordPress site – with an AI that knows nothing but your
own content.

The plugin hands your posts and pages to the Chatbot API, which builds a vector knowledge base
from them. Questions are answered from exactly that content – as a collapsible chat widget, as an
ask field, and as a summarising answer above the WordPress search results.

The API key stays on the server: the browser only ever talks to WordPress, and WordPress talks to
the API.

## Getting started

1. Install and activate the plugin
2. Register at [chatbot.bluebranch.de](https://chatbot.bluebranch.de) and create an API key
3. Store the key under *BlueBranch Chatbot → Settings*
4. Run *BlueBranch Chatbot → Train content* once
5. Switch on *Show the chat button on every page*, or place a block or shortcode

## Requirements

- PHP 7.4 or newer
- WordPress 6.0 or newer
- A chatbot account with an API key from [chatbot.bluebranch.de](https://chatbot.bluebranch.de)

## What differs from the Contao version

Contao fills its knowledge base through the search index crawler. WordPress has no crawler, so it
works the other way round: new content is trained shortly after it is saved, through WP-Cron, and
everything that already existed is trained once from the *Train content* screen.

Rendering goes through the `the_content` filter – the same one the theme uses – so blocks,
shortcodes and page builders come out as the reader sees them.

The API key is stored per site rather than per root page, because WordPress has no equivalent of
Contao's several root pages in one installation. On multisite each site keeps its own key, and
therefore its own knowledge base.

## Blocks and shortcodes

| Block | Shortcode | Purpose |
|---|---|---|
| *Chatbot Widget* | `[bluebranch_chatbot_widget]` | Collapsible chat button |
| *Chatbot Question* | `[bluebranch_chatbot_ask]` | Input field with the answer below it |
| *Chatbot Search Answer* | `[bluebranch_chatbot_search]` | AI answer above the search results |

`[bluebranch_chatbot_exclude]…[/bluebranch_chatbot_exclude]` keeps a region on the page but out of
the knowledge base.

The blocks carry no settings of their own and follow the global configuration; the shortcodes take
attributes for the rare page that needs something different.

## Excluding content

Tick *Exclude from AI answers* in the **BlueBranch Chatbot** box on the post editor. On a
hierarchical post type the setting is inherited by every child page, and the affected entries are
withdrawn from the knowledge base straight away rather than at the next clean-up.

## Privacy and hosting

- **Hosted in Germany.** Servers and data processing are located in Germany only.
- **Our own AI model.** Your content is not passed on to OpenAI, Anthropic or any other third party.
- **No training on your data.** Neither content nor chat histories are used to train models.
- **Data processing agreement.** A DPA under Art. 28 GDPR is available on request by e-mail to
  lb@bluebranch.de.
- **No tracking.** The plugin sets no cookies of its own and stores no IP addresses. Requests to
  the API are made by your server, not by the visitor's browser.
- **The API key stays on the server.** The browser never gets to see it.

The full list of what is sent, and when, is in the *External services* section of
[readme.txt](readme.txt).

## Hooks

See the German table above; the hook names are the same. The most useful one is
`bluebranch_chatbot_rendered_html`, which lets a page builder that stores its layout outside
`post_content` supply its own output.

## Development

```bash
composer install
composer lint
composer fix
```

The plugin passes `WordPress` (Core, Docs and Extra) plus `PHPCompatibilityWP` from PHP 7.4 with
no errors and no warnings. There is no build step: what is in the repository is what runs.

## Vielen Dank

Unser Team dankt für die Unterstützung und das Benutzen vom BlueBranch Chatbot.

Das Team von [www.bluebranch.de](https://www.bluebranch.de/)

<3

## Lizenz

GPL-2.0-or-later – siehe [LICENSE.txt](LICENSE.txt).

## Changes

### 1.0.0 - 2026-09-16

- Erste Veröffentlichung für WordPress, portiert von der Contao-Erweiterung
- Chat-Widget, Fragefeld und Suchantwort als Blöcke und als Shortcodes
- Training beim Veröffentlichen und Aktualisieren über WP-Cron, dazu ein Stapellauf für den Bestand
- Backend-Seite „Trainierte Inhalte" mit Nutzungsstufe, Einzel- und Komplettlöschung und Test-Feld
- Ausschluss je Beitrag, vererbt über den Seitenzweig
- Automatische Bereinigung nicht mehr öffentlicher Inhalte, Intervall wählbar
- Respektiert Passwortschutz und die `noindex`-Angaben von Yoast SEO, Rank Math und SEOPress
- Antworten als Server-Sent Events, über den `http_api_curl`-Haken der WordPress-HTTP-API
- Eigener Markdown-Renderer, der die Antwort vor jeder Regel maskiert – nichts, was die API
  zurückgibt, kann zu Markup werden
- Eigene HTML-zu-Markdown-Wandlung ohne Composer-Abhängigkeit
- Inhalte werden über HTTP gecrawlt, die Sitemap gibt die Grenze vor; ersatzweise `the_content`
- Header, Footer, Menüs und Sidebars werden von der Website selbst markiert und entfernt
- Wiederkehrende Blöcke wie Cookie-Hinweise oder Newsletter-Kästen werden über Wiederholung erkannt
- Hash je Beitrag, damit ein erneuter Lauf nur überträgt, was sich geändert hat
- Bereinigung gleicht zusätzlich gegen die Sitemap ab, mit Sicherung gegen Massenlöschung
