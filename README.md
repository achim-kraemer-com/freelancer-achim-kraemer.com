# freelancer-achim-kraemer.com

Freelancer-Pro mit Login-Bereich im Design von [achim-kraemer.com](https://achim-kraemer.com)
(dunkles Glassmorphism-Theme, Lexend-Schrift, violette Akzente).

Verwaltet **Kunden → Projekte → Aufgaben** und erstellt daraus **Rechnungen als PDF**.

## Fachliches Modell

- **Kunde** – vollständige Kontaktdaten (Name, Firma, E-Mail, Telefon/Mobil, Adresse, Website).
- **Projekt** – gehört zu einem Kunden, hat einen Projektnamen und eine Beschreibung.
- **Aufgabe** – gehört zu einem Projekt, hat einen Namen und entweder
  **Stunden** (berechnet mit **60 € / Std.**) **oder** einen **Festpreis**.
- **Rechnung** – wird aus mehreren offenen Aufgaben **eines** Projekts erzeugt.
  Ein Blanko-PDF wird per Text-Overlay (FPDI + TCPDF) mit Adresse, Positionen und
  Summe gefüllt. Beim Erstellen wählt man die **Rechnungsvorlage** (Briefkopf):
  **Achim Krämer** (`config/pdfs/Musterrechnung.pdf`, aus achim-kraemer.com übernommen)
  oder **Mirwena** (`config/pdfs/Musterrechnung-Mirwena.pdf` mit dem Logo von mirwena.de,
  erzeugt durch `app:build-mirwena-template`).
  Status: **erstellt → verschickt → bezahlt**.
- **E-Mail-Signatur** – steht unter der E-Mail, mit der eine Rechnung verschickt wird
  (nach dem Vorbild des Portals). Als **reiner Text** (hinter der Trennzeile `-- `) oder als
  **HTML** mit Formatierung, Links und Bildern – etwa aus einem Signatur-Generator per
  „Signatur kopieren" in den visuellen Editor eingefügt. Eine HTML-Signatur macht die
  Rechnungsmail zur HTML-Mail (Textteil bleibt erhalten, eingebettete Bilder gehen als
  `cid:`-Anhänge mit). Jede Signatur kann **Vorgabe für eine Rechnungsvorlage** sein;
  **jede Rechnung merkt sich ihre Signatur** und lässt sich auf eine andere umstellen.

### Rechnungs-Workflow

1. Kunde anlegen → Projekt anlegen → Aufgaben mit Stunden oder Festpreis erfassen.
2. Auf der Projektseite offene Aufgaben auswählen, Rechnungsvorlage (Achim Krämer / Mirwena)
   und E-Mail-Signatur wählen (die Vorgabe der Vorlage ist vorausgewählt) →
   **„Rechnung aus Auswahl erstellen"**.
3. Das befüllte PDF wird unter `var/invoices/` erzeugt und ist über die Rechnung
   herunterladbar; abgerechnete Aufgaben werden gesperrt.
4. Auf der Rechnungsseite ggf. die Signatur ändern (mit Vorschau) und die Rechnung
   **per E-Mail senden** – Anschreiben, Signatur und PDF-Anhang. Rechnungsstatus auf
   **verschickt** / **bezahlt** setzen. Beim Löschen einer Rechnung werden die Aufgaben
   wieder als offen freigegeben.

Signaturen werden unter **Signaturen** (`/admin/signature`) verwaltet.

Der CRM-Bereich liegt unter `/admin` und erfordert `ROLE_ADMIN`.

### Kunde aus einem Portal-Kontakt

Auf der Kundenliste führt **„Aus Portal-Kontakt"** (`/admin/customer/portal`) ins
Adressbuch von [portal.achim-kraemer.com](https://portal.achim-kraemer.com). Die
Kontakte kommen lesend über dessen Schnittstelle `GET /api/kontakte` (Token im
Header `X-Api-Token`); gespeichert wird hier nichts davon. **„Übernehmen"** öffnet
das Formular *Neuer Kunde* vorbefüllt – gespeichert wird erst nach Prüfung, denn
drei Felder sind geraten:

| Portal                         | Hier                        | Regel                                                        |
|--------------------------------|-----------------------------|--------------------------------------------------------------|
| ein Namensfeld                 | Vorname + Nachname          | letztes Wort ist der Nachname, Namenszusätze („von", „van der") gehören dazu; „Muster, Max" wird am Komma getrennt; ein einzelnes Wort wird zum Nachnamen |
| Straße mit Hausnummer          | Straße + Hausnummer         | nur eine Nummer **am Ende** wird abgetrennt („Musterweg 7", „Hauptstr. 5a", „Musterweg 7-9"). „Straße des 17. Juni" und „Musterweg 7, 2. OG" bleiben ganz |
| Liste von Nummern ohne Art     | Telefon + Mobil             | Vorwahl entscheidet: 015/016/017 (auch als +49…) ist Mobil, alles andere Telefon |

Mehrere E-Mail-Adressen: die erste gilt. **Notiz und Schlagworte** des Kontakts
kommen nicht mit – dafür hat ein Kunde keine Felder, sie bleiben im Portal.

Einzurichten in `application/.env.local`:

```dotenv
PORTAL_API_URL=https://portal.achim-kraemer.com
PORTAL_API_TOKEN=…
```

Das Token erzeugt das Portal unter **Einstellungen → Profil** (es ist genau einmal
zu sehen). Lokal zeigt die Vorgabe aus `.env` auf den Portal-Stack
(`http://host.docker.internal:8096`). Fehlt das Token oder ist das Portal nicht
erreichbar, sagt die Kontaktauswahl das in einem Satz – die Kundenverwaltung
läuft unverändert weiter.

## Stack

- **Symfony 8.1** (PHP 8.5)
- **Doctrine ORM** + Migrations, **MariaDB 10.6**
- **Security-Bundle** – Formular-Login (`form_login`), CSRF, „Angemeldet bleiben", Access-Control
- **Twig** + **Symfony Form** + handgeschriebenes CSS (kein Build-Step nötig) unter `application/public/css/app.css`
- **PDF-Rechnungen** mit `setasign/fpdi` + `tecnickcom/tcpdf:^6.7` (6.x wegen FPDI-Kompatibilität)
- Lokale Entwicklung über **Docker Compose** (Apache/PHP, DB, Adminer, Mailcatcher)

Das eigentliche Symfony-Projekt liegt im Unterordner `application/`.

## Schnellstart

```bash
# 1) Container starten (Apache/PHP, MariaDB, Adminer, Mailcatcher)
bin/up

# 2) Composer-Abhängigkeiten installieren (falls vendor/ fehlt)
bin/shell composer install

# 3) Datenbankschema anlegen
bin/console doctrine:migrations:migrate --no-interaction

# 4) Benutzer anlegen: E-Mail, Passwort, Rolle
bin/console app:create-user admin@achim-kraemer.com 'Passwort123!' ROLE_ADMIN
```

Danach erreichbar:

| Dienst      | URL                              |
|-------------|----------------------------------|
| App         | http://localhost:8095            |
| Login       | http://localhost:8095/login      |
| Dashboard   | http://localhost:8095/dashboard  (nur angemeldet) |
| Adminer     | http://localhost:8091            |
| Mailcatcher | http://localhost:1081            |

Stoppen mit `bin/down`.

## Test-Zugangsdaten (lokal, Entwicklung)

| E-Mail                     | Passwort       | Rolle       |
|----------------------------|----------------|-------------|
| admin@achim-kraemer.com    | `Passwort123!` | ROLE_ADMIN  |
| user@achim-kraemer.com     | `Passwort123!` | ROLE_USER   |

> Diese Konten sind nur für die lokale Entwicklung. Für Produktion neue Benutzer
> mit starken Passwörtern anlegen und die Werte in `application/.env` (`APP_SECRET`,
> `DATABASE_URL`) über `application/.env.local` überschreiben – niemals echte
> Secrets committen.

## Routen & Struktur

| Route            | Pfad         | Zugriff       |
|------------------|--------------|---------------|
| `app_home`       | `/`          | öffentlich    |
| `app_login`      | `/login`     | öffentlich    |
| `app_logout`     | `/logout`    | –             |
| `app_dashboard`  | `/dashboard` | `ROLE_USER`   |

Wichtige Dateien:

- `application/src/Controller/` – Home, Security (Login/Logout), Dashboard
- `application/src/Entity/User.php`, `application/src/Repository/UserRepository.php`
- `application/src/Command/CreateUserCommand.php` – `app:create-user`
- `application/config/packages/security.yaml` – Firewall & Access-Control
- `application/templates/` – `base.html.twig`, `home/`, `security/login.html.twig`, `dashboard/`
- `application/public/css/app.css` – Design-System (Farben, Buttons, Glass-Cards)
- `application/src/Service/PortalContacts.php` – liest das Adressbuch des Portals;
  `PortalContact.php` (ein Kontakt), `ContactToCustomer.php` (Kontakt → Kunde),
  `PortalError.php` (Portal weg / Token falsch / Antwort kaputt)
- `docker/docker-compose.yml` – lokale Infrastruktur (Projektname `freelancer-ak`)

## Helfer-Skripte (`bin/`)

| Skript           | Wirkung                                  |
|------------------|------------------------------------------|
| `bin/up`         | Docker-Stack starten                     |
| `bin/down`       | Docker-Stack stoppen                     |
| `bin/shell`      | Shell im App-Container (`… <cmd>` optional) |
| `bin/console`    | `php bin/console` im App-Container        |
