# Migrationsauftrag: CPT `tafel` aus WordPress-Multisite herauslösen

> **Für eine neue Chat-Session.** Alle Fakten in diesem Dokument sind am 12.08.2026 direkt auf dem Server verifiziert worden — sie müssen nicht erneut ermittelt werden.

---

## 1. Der Auftrag in drei Sätzen

Der Custom Post Type `tafel` (199 Einträge) liegt derzeit in **Site 2** einer WordPress-Multisite und wird über die Domain `gruenhainichen.com` ausgeliefert. Diese Domain soll künftig eine neue Astro-Website ausliefern, deshalb muss der CPT samt **allen Medien von Antje Wolfeil** in eine andere WordPress-Installation umziehen.

**Kritische Randbedingung:** Auf den historischen Häusertafeln im Ort sind QR-Codes **physisch aufgedruckt**, die auf `https://gruenhainichen.com/tafel/{kürzel}/` zeigen (z. B. `/tafel/g50/`, `/tafel/b172/`). Diese URLs müssen nach der Migration weiterhin funktionieren — ein Nachdruck der Tafeln ist ausgeschlossen.

---

## 2. Ausgangslage (verifiziert)

### Quellsystem

| Was | Wert |
|---|---|
| Hoster | All-Inkl, Konto `w01f6038` |
| SSH-Alias | `vv-wildenstein` (liegt in `~/.ssh/config`, Key `~/.ssh/vv_wildenstein`) |
| WP-Pfad | `/www/htdocs/w01f6038/vv-wildenstein.com/` |
| Installation | **WordPress-Multisite** |
| Site 1 | `https://vv-wildenstein.com/` (Master, dient als CMS für andere Projekte — **nicht anfassen**) |
| Site 2 | `https://gruenhainichen.com/` ← **hier liegen die Tafeln** |
| DB-Präfix | `snS6v_` · Site-2-Tabellen: `snS6v_2_posts`, `snS6v_2_postmeta`, … |
| Uploads Site 2 | `/www/htdocs/w01f6038/vv-wildenstein.com/wp-content/uploads/sites/2/` (**3,6 GB**) |
| WP-CLI | vorhanden unter `/usr/bin/wp`, muss mit `--allow-root` und `--url=https://gruenhainichen.com` aufgerufen werden |

### Der CPT `tafel`

Registriert über das Plugin **Custom Post Type UI** (nicht per Code!). Konfiguration:

```
name:            tafel
label:           Tafel
public:          true
has_archive:     false
rewrite:         true   (slug leer → ergibt /tafel/{post_name}/)
supports:        title, editor, thumbnail
taxonomies:      keine
show_in_rest:    true
menu_icon:       dashicons-cover-image
```

**Wichtig:** Es gibt **keine inhaltlichen Custom Fields**. Der komplette Inhalt steckt im `post_content` als HTML mit eingebetteten Bildern. Die vorhandenen Meta-Keys sind ausschließlich SEO- und System-Kram (`rank_math_*`, `us_og_image`, `_edit_last`, `_wordpress_multisite_*`) und dürfen beim Import verloren gehen.

### Datenmengen

| Was | Anzahl |
|---|---|
| Tafeln (CPT `tafel`) | **199** |
| Im Content eingebundene Bilder | **2.511** |
| Attachments mit `post_parent` = Tafel | **1.706** |
| Attachments von Antje (User 9) gesamt | **1.723** |
| Audio-Dateien (mp3/m4a/wav/ogg) | **14** |
| Dateien mit Präfix `QR*` | **943** |

### Die Redakteurin

| Feld | Wert |
|---|---|
| User-ID (Quelle) | **9** |
| Login / E-Mail | `antje.wolfeil@web.de` |
| Rolle in Site 2 | `editor` |
| Rolle in Site 1 | `subscriber` |
| Super-Admin | nein |

Antje hat **198 der 199 Tafeln** angelegt. Ihre Autorenschaft soll erhalten bleiben.

---

## 3. Vor Beginn zu klären

Diese Punkte stehen noch **nicht** fest und müssen mit Stefan abgestimmt werden:

1. **Wohin genau?** Zielsystem ist eine andere WordPress-Installation. Kandidat aus dem Gespräch: `entdecke-gruenhainichen.de` (läuft auf einem anderen Hoster, Apache 2.4.68 / PHP 8.4.22). Dort existiert bereits ein eigener CPT `tafel` mit **223 Einträgen** — das sind die *Tafeltexte*, während in Site 2 die *Zusatzmaterialien hinter den QR-Codes* liegen. **Vorsicht: Slug-Kollisionen sind möglich.** Alternativ eine frisch aufgesetzte Installation.
2. **SSH-/DB-Zugang zum Ziel** — liegt der vor?
3. **Wie werden die QR-URLs bedient?** Zwei Varianten:
   - **(a)** `gruenhainichen.com/tafel/*` wird per `.htaccess` **301** auf das Zielsystem umgeleitet. Setzt voraus, dass die Slugs (`g50`, `b172`, …) im Ziel identisch bleiben.
   - **(b)** Die neue Astro-Website übernimmt `/tafel/*` selbst und liest die Inhalte aus dem Zielsystem per REST.

   Bis das entschieden ist: **Slugs auf keinen Fall verändern.**

---

## 4. Migrationsplan

### Schritt 0 — Backup (nicht überspringen)

```bash
ssh vv-wildenstein
cd /www/htdocs/w01f6038/vv-wildenstein.com

DATUM=$(date +%Y-%m-%d)
mkdir -p /www/htdocs/w01f6038/_backups_tafeln_$DATUM

# DB-Dump der Site-2-Tabellen
wp --allow-root db export /www/htdocs/w01f6038/_backups_tafeln_$DATUM/site2.sql.gz \
  --tables=snS6v_2_posts,snS6v_2_postmeta,snS6v_2_term_relationships,snS6v_2_terms,snS6v_2_term_taxonomy,snS6v_2_options

# Uploads sichern (3,6 GB — dauert)
tar -czf /www/htdocs/w01f6038/_backups_tafeln_$DATUM/uploads-site2.tar.gz \
  -C /www/htdocs/w01f6038/vv-wildenstein.com/wp-content/uploads sites/2
```

Ein Backup ohne verifizierte Größe ist kein Backup — mit `ls -lh` gegenprüfen.

### Schritt 1 — Zielsystem vorbereiten

Im Ziel-WordPress muss der CPT `tafel` **mit identischem Slug und identischer Rewrite-Regel** existieren, sonst brechen die URLs.

- Entweder **CPT UI** installieren und die Definition aus Abschnitt 2 nachbauen
- Oder per Code in einem mu-plugin registrieren:

```php
register_post_type('tafel', [
    'label'        => 'Tafel',
    'public'       => true,
    'has_archive'  => false,
    'rewrite'      => ['slug' => 'tafel', 'with_front' => false],
    'supports'     => ['title', 'editor', 'thumbnail'],
    'show_in_rest' => true,
    'menu_icon'    => 'dashicons-cover-image',
]);
```

Danach **Permalinks neu speichern** (`wp rewrite flush`).

Ebenfalls anlegen: Benutzerkonto für `antje.wolfeil@web.de` mit Rolle `editor`.

### Schritt 2 — Export aus der Quelle

```bash
ssh vv-wildenstein
cd /www/htdocs/w01f6038/vv-wildenstein.com

wp --allow-root --url=https://gruenhainichen.com export \
  --post_type=tafel \
  --dir=/www/htdocs/w01f6038/_export_tafeln \
  --filename_format=tafeln-{n}.xml
```

Das erzeugt eine WXR-Datei. **Prüfen:** Enthält sie 199 `<item>`-Elemente mit `<wp:post_type>tafel</wp:post_type>`?

Zusätzlich die Attachments exportieren, die an Tafeln hängen:

```bash
# IDs der Tafel-Attachments ermitteln
wp --allow-root db query "
SELECT GROUP_CONCAT(a.ID)
FROM snS6v_2_posts a
JOIN snS6v_2_posts p ON a.post_parent = p.ID
WHERE a.post_type='attachment' AND p.post_type='tafel'" --skip-column-names
```

### Schritt 3 — Mediendateien übertragen

**Der kritische Teil.** Der WordPress-Importer würde jede Datei einzeln per HTTP nachladen — bei 1.706 Dateien und 3,6 GB führt das regelmäßig zu Timeouts und halb importierten Beständen.

Besserer Weg: Dateien vorab direkt kopieren.

```bash
# Von der Quelle zum Ziel (Pfade im Ziel anpassen)
rsync -avz --progress \
  vv-wildenstein:/www/htdocs/w01f6038/vv-wildenstein.com/wp-content/uploads/sites/2/ \
  ziel-server:/pfad/zum/ziel/wp-content/uploads/
```

**Achtung Ordnerstruktur:** In der Quelle liegen die Dateien unter `uploads/sites/2/2025/12/datei.jpg`, im Ziel (Single-Site) gehören sie nach `uploads/2025/12/datei.jpg`. Der `sites/2`-Teil fällt weg — genau wie im `rsync`-Befehl oben.

**Kollisionen prüfen:** Existieren im Ziel bereits Dateien mit gleichem Namen im selben Monatsordner? Vorher mit `--dry-run` testen.

### Schritt 4 — Import ins Ziel

```bash
wp import tafeln-1.xml --authors=create
```

Falls der Importer die bereits kopierten Dateien erneut laden will, kann `--skip=attachment` gesetzt und die Attachment-Datensätze anschließend per Skript angelegt werden. Das ist aufwändiger, aber robuster bei großen Beständen.

### Schritt 5 — URLs im Content umschreiben

Nach dem Import zeigen alle Bild-URLs im `post_content` noch auf die alte Domain und den Multisite-Pfad. **2.511 Vorkommen.**

```bash
# Erst trocken testen!
wp search-replace \
  'https://gruenhainichen.com/wp-content/uploads/sites/2/' \
  'https://ZIELDOMAIN/wp-content/uploads/' \
  --post_type=tafel --dry-run --report-changed-only

# Wenn die Zahlen plausibel sind, ohne --dry-run wiederholen
```

Zusätzlich prüfen auf Varianten: `http://` statt `https://`, Domain mit/ohne `www.`, protokollrelative URLs (`//gruenhainichen.com/...`).

### Schritt 6 — Verifikation

Nach der Migration muss **jeder** dieser Punkte grün sein:

```bash
# 1. Sind alle 199 Tafeln da?
wp post list --post_type=tafel --format=count

# 2. Stimmen die Slugs? (Stichprobe der QR-Kürzel)
wp post list --post_type=tafel --field=post_name | grep -E '^(g50|b172|b130|g49|w6)$'

# 3. Beitragsbilder gesetzt?
wp post list --post_type=tafel --format=ids | while read id; do
  wp post meta get $id _thumbnail_id >/dev/null 2>&1 || echo "FEHLT: $id"
done

# 4. Keine alten URLs mehr im Content?
wp db query "SELECT COUNT(*) FROM wp_posts
  WHERE post_type='tafel' AND post_content LIKE '%uploads/sites/2%'"
# muss 0 ergeben
```

**Und der wichtigste Test — händisch im Browser:**
Mindestens fünf Tafeln aufrufen und prüfen, ob **alle Bilder laden** und die **Audios abspielbar** sind. Empfohlene Stichprobe: `g50` (3 Bilder), `b172`, `b130`, `g25`, `w6`.

### Schritt 7 — QR-URLs sicherstellen

Je nach Entscheidung aus Abschnitt 3:

**Variante (a) — Redirect:** Auf `gruenhainichen.com` in die `.htaccess`:

```apache
RewriteEngine On
RewriteRule ^tafel/(.+)$ https://ZIELDOMAIN/tafel/$1 [R=301,L]
```

**Variante (b) — Astro übernimmt:** Die neue Website baut `/tafel/{slug}/` selbst und liest per REST aus dem Zielsystem.

**In beiden Fällen mit einem echten Handy testen** — QR-Code scannen, nicht nur die URL im Browser tippen.

---

## 5. Fallstricke

| Risiko | Warum es passiert | Gegenmaßnahme |
|---|---|---|
| **Slug-Kollision** | Falls Ziel = `entdecke-gruenhainichen.de`: Dort existieren bereits 223 Tafeln. Bei gleichem Slug hängt WordPress ein `-2` an → **QR-Code tot** | Vor dem Import Slug-Listen beider Systeme vergleichen. Keine Kollision bei den Kürzeln erwartet (dort sprechende Slugs), aber **verifizieren** |
| **Attachment-IDs verschieben sich** | Import vergibt neue IDs, `_thumbnail_id` zeigt ins Leere | WXR-Import mappt automatisch — nach Schritt 6.3 prüfen |
| **Dateien doppelt** | Werden Dateien sowohl per rsync kopiert *als auch* vom Importer geladen, entstehen `datei-1.jpg`-Dubletten | `--skip=attachment` oder rsync erst *nach* dem Import |
| **Timeout bei großem Import** | 199 Posts + 1.706 Medien in einem Durchlauf | In Batches importieren, PHP `max_execution_time` hochsetzen |
| **Antje verliert Zugang** | Ihr Konto existiert nur in der Quelle | Schritt 1: Konto im Ziel anlegen, **bevor** importiert wird |
| **Rank-Math-Meta stört** | SEO-Plugin im Ziel interpretiert alte Meta-Keys | Unkritisch — können bleiben oder gelöscht werden |

---

## 6. Nach der Migration: Zugang für Antje einschränken

Gewünscht ist, dass Antje im Ziel-Backend **nur Tafeln und Medien** sieht. Als mu-plugin (`wp-content/mu-plugins/tafel-redaktion.php`):

```php
<?php
/**
 * Rolle "Tafel-Redaktion": darf ausschließlich Tafeln und Medien verwalten.
 */

// Rolle einmalig anlegen
add_action('init', function () {
    if (get_role('tafel_redaktion')) return;
    add_role('tafel_redaktion', 'Tafel-Redaktion', [
        'read'                   => true,
        'upload_files'           => true,
        'edit_posts'             => true,
        'edit_published_posts'   => true,
        'publish_posts'          => true,
        'delete_posts'           => true,
        'edit_others_posts'      => true,
    ]);
});

// Menü aufräumen
add_action('admin_menu', function () {
    if (!current_user_can('tafel_redaktion')) return;
    remove_menu_page('edit.php');                  // Beiträge
    remove_menu_page('edit.php?post_type=page');   // Seiten
    remove_menu_page('edit-comments.php');         // Kommentare
    remove_menu_page('themes.php');                // Design
    remove_menu_page('plugins.php');
    remove_menu_page('tools.php');
    remove_menu_page('options-general.php');
}, 999);
```

Danach Antjes Rolle von `editor` auf `tafel_redaktion` ändern:

```bash
wp user set-role antje.wolfeil@web.de tafel_redaktion
```

**Vor der Übergabe an Antje selbst einloggen und gegenprüfen**, dass sie Tafeln anlegen, bearbeiten und Bilder hochladen kann — eine zu restriktive Rolle ist genauso ein Problem wie eine zu offene.

---

## 7. Rollback

Solange Site 2 nicht gelöscht ist, ist der Rückweg trivial: Die Quelle bleibt unverändert, es wurde nur kopiert.

**Site 2 erst löschen, wenn:**
1. Alle 199 Tafeln im Ziel verifiziert sind
2. Die QR-Codes mit einem echten Handy getestet wurden
3. Antje sich eingeloggt und eine Tafel testweise bearbeitet hat
4. Mindestens zwei Wochen Parallelbetrieb ohne Beanstandung liefen

Bei Problemen: DB-Dump und Uploads-Archiv aus Schritt 0 zurückspielen.

---

## 8. Kontext, der beim Verstehen hilft

- **Achtung, seit dem 16.08.2026 geänderte Adressen** (siehe [GO-LIVE-2026-08-16.md](GO-LIVE-2026-08-16.md)): Das alte WordPress mit den Tafeln erreichst du unter **`alt.gruenhainichen.com`**, nicht mehr unter `gruenhainichen.com`. Unter `www.gruenhainichen.com` liegt seither die neue Astro-Website (statische Dateien, kein WordPress).
- Das alte WordPress ist **keine eigenständige Installation**, sondern Site 2 im Multisite-Netzwerk von `vv-wildenstein.com` (Tabellen `snS6v_2_*`). Ein Eingriff dort kann Site 1 beeinflussen — dort läuft das CMS für die neue Astro-Website sowie zwei selbst entwickelte Plugins (`vvw-roombooking`, `vvw-amtsblatt`).
- Site 2 steht auf `blog_public = 0` und ist damit für Suchmaschinen gesperrt. Das ist so gewollt und sollte so bleiben.
- Die QR-Codes auf den Tafeln zeigen physisch auf `gruenhainichen.com/tafel/{kürzel}/`. Eine `.htaccess`-Regel der neuen Seite leitet sie auf `alt.gruenhainichen.com` weiter. **Nach dem Umzug muss diese Regel auf das neue Ziel umgebogen werden** — sie steht in `apps/gruenhainichen/public/.htaccess` im Repo `gruenhainichen`, nicht auf dem Server.
- `entdecke-gruenhainichen.de` ist eine **separate** WordPress-Installation bei einem anderen Hoster. Sie zeigt die *Tafeltexte* (die Geschichte auf der Tafel), während Site 2 die *Zusatzmaterialien hinter dem QR-Code* enthält (Brandkataster, historische Ansichten, Audios). Beide Bestände sind inhaltlich verschieden — eine Zusammenführung ist möglich, aber ein eigenes Projekt.
- Beim Titel-Abgleich beider Bestände ließen sich **178 von 198** Tafeln automatisch zuordnen, **20 nicht** (abweichende Titel). Diese Liste liegt nicht vor und müsste bei Bedarf neu erzeugt werden.

---

*Erstellt am 12.08.2026. Alle Zahlen per SSH und WP-CLI auf dem Produktivsystem verifiziert.*
