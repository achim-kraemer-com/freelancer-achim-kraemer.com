# freelancer-achim-kraemer.com

Freelancer-CRM mit Login-Bereich im Design von [achim-kraemer.com](https://achim-kraemer.com)
(dunkles Glassmorphism-Theme, Lexend-Schrift, violette Akzente).

Verwaltet **Kunden → Projekte → Aufgaben** und erstellt daraus **Rechnungen als PDF**.

## Fachliches Modell

- **Kunde** – vollständige Kontaktdaten (Name, Firma, E-Mail, Telefon/Mobil, Adresse, Website).
- **Projekt** – gehört zu einem Kunden, hat einen Projektnamen und eine Beschreibung.
- **Aufgabe** – gehört zu einem Projekt, hat einen Namen und entweder
  **Stunden** (berechnet mit **60 € / Std.**) **oder** einen **Festpreis**.
- **Rechnung** – wird aus mehreren offenen Aufgaben **eines** Projekts erzeugt.
  Das Blanko-PDF (`config/pdfs/Musterrechnung.pdf`, aus achim-kraemer.com übernommen)
  wird per Text-Overlay (FPDI + TCPDF) mit Adresse, Positionen und Summe gefüllt.
  Status: **erstellt → verschickt → bezahlt**.

### Rechnungs-Workflow

1. Kunde anlegen → Projekt anlegen → Aufgaben mit Stunden oder Festpreis erfassen.
2. Auf der Projektseite offene Aufgaben auswählen → **„Rechnung aus Auswahl erstellen"**.
3. Das befüllte PDF wird unter `var/invoices/` erzeugt und ist über die Rechnung
   herunterladbar; abgerechnete Aufgaben werden gesperrt.
4. Rechnungsstatus auf **verschickt** / **bezahlt** setzen. Beim Löschen einer
   Rechnung werden die Aufgaben wieder als offen freigegeben.

Der CRM-Bereich liegt unter `/admin` und erfordert `ROLE_ADMIN`.

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
- `docker/docker-compose.yml` – lokale Infrastruktur (Projektname `freelancer-ak`)

## Helfer-Skripte (`bin/`)

| Skript           | Wirkung                                  |
|------------------|------------------------------------------|
| `bin/up`         | Docker-Stack starten                     |
| `bin/down`       | Docker-Stack stoppen                     |
| `bin/shell`      | Shell im App-Container (`… <cmd>` optional) |
| `bin/console`    | `php bin/console` im App-Container        |
