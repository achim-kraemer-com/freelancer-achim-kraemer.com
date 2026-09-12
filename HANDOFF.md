# Projekt-Kontext: freelancer-achim-kraemer.com (Freelancer-Pro)

> Handoff-Zusammenfassung für einen neuen Chat / weitere Bearbeitung.

## Überblick
Neue Symfony-App als **Freelancer-Pro**: Verwaltung von **Kunden → Projekten → Aufgaben → Rechnungen (PDF)**. Login-Bereich im Design von achim-kraemer.com (dunkles Glassmorphism, Lexend-Font, violette Akzente). Liegt im Repo `freelancer-achim-kraemer.com`, die eigentliche App im Unterordner **`application/`**.

## Stack
- **Symfony 8.1 / PHP 8.5**, Doctrine ORM + Migrations, **MariaDB 10.6**
- Security-Bundle (`form_login`, CSRF, remember-me, Access-Control), Symfony Form, Twig
- **Buildloses CSS** (kein Webpack/Tailwind) in `application/public/css/app.css`
- PDF-Rechnungen mit **`setasign/fpdi` + `tecnickcom/tcpdf:^6.7`** (6.x zwingend — tcpdf 7.x bricht FPDI mit „unable to read file: helvetica.json")

## Domänenmodell
- **Customer** — vollständige Kontaktdaten (Name, Firma, E-Mail, Telefon/Mobil, Adresse, Website, aktiv)
- **Project** — gehört zu Customer, hat Name + Beschreibung
- **Task** (Aufgabe) — gehört zu Project; entweder `hours` (× **60 €/Std.**, `Task::HOURLY_RATE`) **oder** fester `price`. `getCost()` rechnet automatisch; `isHourly()` = price null & hours gesetzt
- **Invoice** — aus mehreren offenen Tasks **eines** Projekts; Status **erstellt → verschickt → bezahlt** (`Invoice::STATUS_*`). Abgerechnete Tasks werden gesperrt (`invoiced=true`). Feld `template` (`App\Enum\InvoiceTemplate`): **Achim Krämer** oder **Mirwena** — bestimmt das Blanko-PDF (Briefkopf/Logo), wird beim Erstellen auf der Projektseite gewählt

## Wichtige Architektur/Dateien
- `src/Controller/`: CustomerController, ProjectController (inkl. `createInvoice`), TaskController, InvoiceController, SecurityController, HomeController, DashboardController
- `src/Service/PdfService.php` — legt Text-Overlay (mm-Koordinaten) über das Blanko-PDF der gewählten Vorlage: `config/pdfs/Musterrechnung.pdf` (Achim Krämer, aus achim-kraemer.com übernommen, Google-Docs-Export) bzw. `config/pdfs/Musterrechnung-Mirwena.pdf` (Mirwena)
- `src/Service/InvoicePdfBuilder.php` — **geteilte** Overlay-Logik (Controller + Command); Absender/Bank/„Kleinunternehmer §19"-Zeile kommen fix aus dem Blanko-PDF. Die Overlay-Koordinaten gelten für beide Vorlagen (Mirwena-Vorlage hat dieselben Positionen)
- `src/Command/BuildMirwenaTemplateCommand.php` (`app:build-mirwena-template`) — erzeugt `Musterrechnung-Mirwena.pdf` aus `config/pdfs/mirwena-logo.png` (Lockup von mirwena.de) + festen Briefkopf-Texten (E-Mail `kontakt@mirwena.de`, Internet `mirwena.de`, sonst wie Achim Krämer). Nach Textänderungen erneut ausführen und das PDF committen
- `src/Command/`: `app:create-user <email> <pw> <role>`, `app:generate-invoice-pdfs [--missing-only]`
- CRM liegt unter **`/admin` (ROLE_ADMIN)**; Login via Entity-User-Provider

## Lokale Entwicklung (Docker)
- Compose: `docker/docker-compose.yml`, Projektname **`freelancer-ak`** (eigener `name:`, damit fremde Container wie `elb-portal` nicht angefasst werden)
- Ports: **App http://localhost:8095**, Adminer 8091, Mailcatcher 1081, MariaDB 3307
- Helfer: `bin/up`, `bin/down`, `bin/shell`, `bin/console`
- Container: `freelancer-ak-app` (webdevops/php-apache:8.5), `freelancer-ak-db`
- Test-Login: **admin@achim-kraemer.com** / `Passwort123!` (ROLE_ADMIN); außerdem `kontakt@achim-kraemer.com` (ROLE_ADMIN, vom Nutzer gesetztes Passwort)

## Bisher erledigt
1. Symfony-Skeleton + Security + dunkler Login/Landing/Dashboard, end-to-end getestet
2. Volles CRM (CRUD Kunde/Projekt/Aufgabe) + Rechnungserstellung aus Aufgaben + PDF-Befüllung, getestet
3. **Legacy-Daten aus `achim-kraemer_com.sql` importiert**: 6 Kunden, 5 Projekte, 16 Aufgaben, 10 Rechnungen. Mapping: `time_entry`→Task (hours gesetzt ⇒ stündlich/price NULL; hours NULL ⇒ Festpreis), Rechnungsstatus `offen`→`verschickt`, `bezahlt`→`bezahlt`, Projekt der Rechnung aus verknüpften Tasks abgeleitet. Alle 10 Rechnungs-PDFs regeneriert; Summen stimmen exakt

## Git / Secrets (aktueller Stand)
- Remotes: **`origin`** = GitHub (`git@github.com:achim-kraemer-com/freelancer-achim-kraemer.com.git`), **`main`** = Deploy-Server (SSH)
- `application/.env` (APP_SECRET + DB) und `application/.env.dev` (APP_SECRET) waren versehentlich committet → **aus Tracking entfernt, in `.gitignore`, APP_SECRET rotiert** (neu, in beiden lokalen Dateien), **History umgeschrieben** (`filter-branch`) und **force-gepusht zu `origin`** (jetzt sauber, Commit `72900a7`)
- **Offen:** Der **Deploy-Server (`main`-Remote)** hat noch die alte History mit den (rotierten, wertlosen) alten Secrets — bewusst nicht angefasst. Falls die Live-App das alte `APP_SECRET` nutzt, sollte es serverseitig ebenfalls auf den neuen Wert gesetzt werden

## Bekannte Punkte / mögliche nächste Schritte
- Rechnungen sind **Kleinunternehmer ohne USt.** (kommt so aus dem Blanko-PDF) — MwSt.-Ausweis noch nicht implementiert
- App-Code (außer der `.env`-Bereinigung) ist ansonsten committet; ggf. Deploy-History bereinigen (`git push --force-with-lease main main` — vorher Deploy-Mechanismus prüfen)
