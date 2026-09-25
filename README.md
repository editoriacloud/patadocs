# PATADOCS — *Find the Document You Need.*

Kenya's document discovery, sharing and download hub — pure **PHP + MySQL + vanilla JS/AJAX**, no framework, no Composer, no Node.
Search → find → protected preview → free download or **M-Pesa (through your Payment Hub)** → instant download.
No customer accounts. Community contributions. Powerful admin. SEO-first.

The visual design is the supplied retro "classic Windows / WebForms" interface, preserved as-is
(`assets/css/style.css` is the original CSS; everything new lives in `assets/css/extra.css` and reuses the same tokens).

---

## 1. Requirements

| | |
|---|---|
| PHP | 7.4 or newer (8.x recommended) with `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `json`. Recommended: `zip` (DOCX), `curl` (Payment Hub), `imagick` |
| Database | MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4) |
| Web server | Apache with `mod_rewrite` (cPanel/XAMPP). Nginx works — see §9 |
| Optional (better previews) | `pdftoppm` (poppler-utils) **or** Imagick + Ghostscript for PDFs · LibreOffice for DOC/DOCX. Without them PATADOCS falls back automatically (see §6) |

## 2. Installation

### cPanel / shared hosting
1. In cPanel create a **MySQL database + user** (give the user *ALL PRIVILEGES*).
2. Upload the contents of this folder to `public_html/` (or a sub-folder) and extract.
3. *(Recommended)* create a folder **above** `public_html`, e.g. `/home/USER/patadocs_private`, and enter it in the installer as *Private documents folder*. If you skip this, `private_documents/` inside the site is used — it is protected by `.htaccess` and only served through `download.php`.
4. Open `https://YOUR-DOMAIN/install.php`, fill in the database + admin details, press **Install**.
   The installer imports `database.sql`, writes `includes/config.php`, creates your Super Admin and deletes itself.
5. Sign in at `/admin/` → **Settings** → configure the **Payment Hub** (§4), logo, contact details.
6. Once your SSL certificate is active, uncomment the HTTPS redirect at the top of `.htaccess`.

### XAMPP (Windows / macOS / Linux)
1. Copy the folder to `htdocs/patadocs`, start Apache + MySQL.
2. Create an empty database `patadocs` in phpMyAdmin (collation `utf8mb4_unicode_ci`).
3. Open `http://localhost/patadocs/install.php` (user `root`, empty password).

### Manual install (no installer)
`mysql -u USER -p DBNAME < database.sql`, copy `includes/config.example.php` → `includes/config.php`, edit it, then create the first admin:
```sql
INSERT INTO admin_users (username, email, full_name, password_hash, role)
VALUES ('admin', 'you@example.com', 'Admin', '<output of: php -r "echo password_hash(\'YourPassword123\', PASSWORD_DEFAULT);">', 'SUPER_ADMIN');
```

**Folder permissions:** `uploads/previews`, `uploads/temporary`, `private_documents` (and `includes/` during install) must be writable by PHP (755/775).
**Upload size:** documents can only be as large as your PHP `upload_max_filesize` / `post_max_size`. A `.user.ini` with 32 MB is included (works on PHP-FPM hosts).

## 3. Project structure (shallow on purpose)

```
/                     index, search, document, category, collection, categories, popular, contribute,
                      request-document, recover, saved, about, contact, download, payment, payment-success,
                      router (clean URLs), sitemap, install
admin/                login, index (dashboard), documents, upload, edit-document, categories, metadata,
                      collections, homepage, synonyms, contributions, requests, reports, orders, payments,
                      downloads, analytics, settings, users, profile, file (admin-only original viewer)
ajax/                 search, payment, payment-status, webhook, download, contribute, public, admin
includes/             config(.example), init, db, functions, catalog, security, view, header, footer,
                      admin_header, admin_footer, admin_lib, admin_docform, preview, payment_hub
assets/               css/style.css (original design) · css/extra.css · js/app.js · js/admin.js · fonts · images
uploads/previews/     watermarked previews only (public, random folder names)
uploads/temporary/    scratch space + cache (web access denied)
private_documents/    ORIGINAL files (web access denied, random file names)
```

## 4. Payment Hub (M-Pesa) integration

PATADOCS contains **no Daraja code**. It uses the Editoria Payment Hub (`https://payments.editoriaweb.co.ke`): the server creates an
invoice, the customer pays in the Hub's embedded modal (STK or PayBill), the Hub confirms, PATADOCS validates and unlocks the download.

**In the Hub** (Applications → PATADOCS): copy the Client ID + Client secret, add your site (e.g. `https://knickpoint.co.ke/patadocs`)
to *Allowed to embed*, and on the Webhooks page register `https://YOUR-DOMAIN/ajax/webhook.php` and copy its secret.
**In PATADOCS** (Admin → Settings → Payment Hub): Hub URL, Client ID, Client secret, Webhook secret → Save → *Test connection*.

Flow: `BUY & DOWNLOAD` → phone → **PENDING** order `DOC-XXXXXXXX` → server `POST /api/v1/invoices` → browser
`EditoriaPay.open({ token: payment_intent.id })` → customer pays → (a) signed `payment.confirmed` webhook and/or (b) PATADOCS asks
`GET /api/v1/payment-intents/{id}/status` (server-to-server) → **PAID** → download token(s) issued → "PAYMENT SUCCESSFUL" + auto-download.
The modal's `onSuccess` callback is only a signal to start checking — it never unlocks anything by itself.

```
POST /api/v1/auth/token                  { client_id, client_secret }  → bearer token (1 h, cached in the settings table)
POST /api/v1/invoices                    Authorization: Bearer …   Idempotency-Key: DOC-XXXXXXXX
  { external_invoice_id: "DOC-XXXXXXXX", amount, customer_phone: "07…", customer_name, description }  → payment_intent.id
GET  /api/v1/payment-intents/{id}/status
Webhook → POST /ajax/webhook.php
  X-Editoria-Event-Id, X-Editoria-Timestamp,
  X-Editoria-Signature: sha256=HMAC_SHA256("{event id}.{timestamp}.{raw body}", webhook secret)
```
`<script src="https://payments.editoriaweb.co.ke/pay/widget.js">` is added to `<head>` only on pages with a Buy button
(`$meta['payment_widget']`). Without JavaScript, `payment.php` links to the Hub's hosted payment page instead.
Response field names are mapped in one function, `hub_normalize()` in `includes/payment_hub.php`.

**Payment security (all enforced server-side):** browser "success" is never trusted · order code, amount, currency and payment intent are
validated · an M-Pesa receipt can belong to only one order · a repeat click reuses the unpaid order and its invoice (and the Idempotency-Key
prevents duplicate invoices) · already-paid phone+document is blocked · webhooks need a valid HMAC, a timestamp within 5 minutes and a
never-seen event id · tokens are issued exactly once inside a locked transaction · the order status endpoint needs the order's secret
access key (guessing order numbers reveals nothing) · client secret and webhook secret never leave the server.

## 5. Secure downloads & purchase recovery
* Originals live in `private_documents/` (denied by `.htaccess`, random names) — the browser never receives them except through `download.php?token=…`.
* Tokens: 64 hex characters from `random_bytes`, linked to document + order, expiry + download limit (Settings), counted, logged (first/last download).
* Free documents also use short-lived tokens (created by a CSRF-protected POST).
* **Recover purchase** (`/recover`): Order ID **+ phone** *or* the M-Pesa code. Attempts are rate-limited.
* Admin → Downloads: revoke, regenerate, extend. Admin → Orders: re-check with the Hub, mark refunded (revokes links).

## 6. Protected previews
Originals are never shown. On upload PATADOCS builds a **separate** JPEG preview (first *N* pages, reduced quality, **watermark burned into the pixels** + footer strip).
Watermark text, strength, quality, width and default pages: **Settings → Preview** (per-document page limit in the upload wizard).

Rendering (first that works on your server): PDF → `pdftoppm` → Imagick → Ghostscript · DOCX/DOC → LibreOffice → PDF · DOCX without LibreOffice → text-only pages ·
images → resized. If nothing can render a file, upload preview images manually in wizard step 7 (they are watermarked identically).

## 7. Admin
Roles: `SUPER_ADMIN` (everything) · `ADMIN` · `CONTENT_MANAGER` · `REVIEWER`. Permissions live in the database and are editable in **Admin Users & Roles**.
Login: `password_hash`/`password_verify`, session ID regenerated on login, idle timeout, lockout after failed attempts, login activity log, all admin writes logged.
Wizard (9 steps): Upload → Basic info → Category → Metadata → Tags → Pricing → Preview → SEO → Publish (Save Draft / Save & Preview / Publish at any step).
Categories: unlimited depth, moved/renamed safely (URLs and search text rebuilt). Metadata fields belong to a category and are inherited by all its sub-categories, so **nothing is hard-coded to Education**.

## 8. SEO
**On every page:** one canonical URL, `<title>` + meta description (≤160 chars, unique per page — paginated pages say "Page N of M"),
`robots` with `max-snippet:-1, max-image-preview:large` for indexable pages, `X-Robots-Tag: noindex` headers on everything that must stay out
of Google (admin, AJAX, payment, download, recover, search results, error pages), Open Graph + Twitter cards, `lang="en-KE"`, `rel=prev/next`.

**Structured data (JSON-LD):** `WebSite` (+ search box) and `Organization` (logo, contact, Kenya) on the home page, `WebPage` + **`Product`/`Offer`**
(price in KES, availability, preview images, format/pages) on document and bundle pages, **`AggregateRating` + `Review`** once verified-buyer reviews
are approved, `CollectionPage`/`ItemList` on categories, `BreadcrumbList` everywhere. Test any page at https://search.google.com/test/rich-results.

**Crawling:** `/sitemap.xml` is a sitemap index → `sitemap-pages.xml`, `sitemap-categories.xml`, `sitemap-collections.xml`, `sitemap-documents-N.xml`
(5,000 per file, with preview images for Google Images). `<lastmod>` only changes when content really changes (views, downloads and purchases no longer
touch `updated_at`). `/robots.txt` is generated with the right folder prefix. **IndexNow** tells Bing/Yandex/Seznam instantly when a document is
published, edited, unpublished or deleted (key file `/<key>.txt`). The first preview image is in the HTML, so Google can see it.

**URLs:** readable paths (`/education/grade-7/mathematics/<slug>`); non-canonical variants 301 to the canonical one (query-string URLs, wrong category
prefix, `http://`, `www.` — set `BASE_URL` in `config.php`); removed (archived) documents answer **410 Gone** so they drop out of the index quickly.

**Admin → Analytics & SEO → SEO health** audits every published document: title length (with the site suffix), meta description length, duplicate
titles/descriptions, thin text (<50 words), missing preview or category — each with a FIX link — plus the technical checklist.

After go-live: add the site to **Google Search Console** and **Bing Webmaster Tools** and submit `https://YOUR-DOMAIN/sitemap.xml`.
If PATADOCS lives in a **sub-folder** (e.g. `/patadocs`), search engines ignore its robots.txt — copy the generated lines (shown on the SEO health page)
into the domain's root `robots.txt`.
No mod_rewrite? Turn off **Settings → SEO → Clean URLs**; the site then uses `document.php?slug=…` links.

## 8b. Verified-buyer reviews
After paying, the buyer can rate each document (1–5 stars + comment) on the payment-success page — the order's secret key proves the purchase,
one review per document per order. Reviews wait in **Admin → Reviews** (or publish instantly: Settings → Documents → auto-approve) and then show on
the document page and as star ratings in Google.
Database changes are applied automatically: `includes/migrate.php` upgrades existing installs on the first request after new code is uploaded.

## 8c. Automation engine
PATADOCS runs its own background jobs — **Admin → Automation** shows each job, its last result, a *Run now* button, switches and the run log.

| Job | Every | What it does |
|---|---|---|
| Payment reconciliation | 5 min | Re-checks unpaid (and recently expired) orders with the Payment Hub, so a payment whose webhook got lost still unlocks the download; expires abandoned orders. |
| Document text extraction | 15 min | Reads the text inside PDFs (`pdftotext`), Word `.docx` (built in), `.doc` (`antiword`) and images (`tesseract` OCR) — used for **full-text search** and the “From inside the document” text on the page. |
| Auto-SEO | 1 h | Fills **empty** meta descriptions (from the document's own first lines) and keywords; never overwrites what an admin typed. Changed pages go to IndexNow. |
| Preview generation | 1 h | Builds missing watermarked previews for published documents. |
| Review requests | 1 h | Emails buyers (who gave an email) 2 days after purchase with a one-click rating link. |
| Search vocabulary | 12 h | Learns the words in titles, tags, categories and documents → search fixes typos (“mathmatics” → *Showing results for mathematics*; “Did you mean…”). |
| Daily report | 1 day | Emails the admin yesterday's sales, best sellers, top searches, **searches with no results** (documents people want) and pending work. |
| Housekeeping | 1 day | Cleans rate limits, old logs and temp files; expires old download links. |

**How it runs:** add a server cron (cPanel → Cron Jobs, every 5 minutes): `php /home/USER/public_html/patadocs/cron.php` — or, URL-only hosts:
`wget -q -O /dev/null "https://YOUR-DOMAIN/cron.php?key=CRON_KEY"` (key on the Automation page). Without cron, the built-in **web cron** runs due jobs
after a visitor's page has been sent (no delay for the visitor). A database lock prevents overlapping runs; each run has a time budget.
Run one job by hand: `php cron.php seo`. A failing job shows a red badge in the admin menu and in the daily report.

## 9. Nginx
```nginx
location / { try_files $uri $uri/ /router.php?path=$uri&$args; }          # clean URLs
location = /sitemap.xml { rewrite ^ /sitemap.php last; }
location = /robots.txt  { rewrite ^ /robots.php last; }
location ~ ^/sitemap-([a-z]+)(-([0-9]+))?\.xml$ { rewrite ^ /sitemap.php?part=$1&n=$3 last; }
location ~ ^/([a-f0-9]{32})\.txt$ { rewrite ^ /indexnow.php?key=$1 last; }
location ~ ^/(private_documents|includes|uploads/temporary)/ { deny all; }
location ~* \.(sql|md|log|ini|bak|sh|inc)$ { deny all; }
location ~ ^/uploads/.*\.php$ { deny all; }
```
(Also map `/search`, `/about`, … to `search.php`, `about.php`, … like the `RewriteRule` in `.htaccess`.)

## 10. Security summary
PDO prepared statements only · output escaped with `htmlspecialchars` · CSRF tokens on every state-changing form/AJAX call · session cookies `HttpOnly` + `SameSite=Lax` (+`Secure` on HTTPS), User-Agent-bound sessions, ID regeneration on login ·
uploads validated by extension whitelist, real MIME (finfo), magic bytes, structure (DOCX zip / image decode / PDF trailer), script-injection scan, renamed randomly, stored outside public folders ·
directory traversal blocked in router and download endpoints · rate limits (payments, recovery, contributions, requests, reports, free downloads, login) · honeypot + timing check on public forms ·
security headers (`nosniff`, frame protection, referrer policy, CSP `object-src 'none'`).

## 11. Maintenance & scaling
* Backups: database + `private_documents/` (originals). Previews can always be regenerated (Admin → edit document → *Regenerate preview*).
* Search uses a denormalised `documents.search_text` with `LIKE` matching, synonyms and ranking — fast for tens of thousands of documents. Rebuild it from **Settings → Documents → Rebuild search index**.
* Errors are logged to `private_documents/error.log` (never shown to visitors). Set `APP_ENV` to `development` in `includes/config.php` only while debugging.
* Emails (receipts, contribution notices) use PHP `mail()`; on hosts without it, they are simply skipped.

## 12. What was verified
Functional tests were run against a live PHP 8.3 + MariaDB 10.11 instance with a mock Payment Hub: installer, admin login/lockout/roles, category & metadata management,
upload wizard (PDF/DOCX/PNG) with preview generation, upload-attack rejection, public pages/search/filters/synonyms/sitemap, the full M-Pesa flow
(order → pending → paid via status check **and** via signed webhook, replay/bad-signature/amount-mismatch/duplicate-receipt rejection, token limits, recovery, bundles, refund revocation),
contributions review → publish, requests/reports/contact, and the browser JavaScript (live search, viewer, payment modal, filters, wizard) in a DOM test harness.
The dashboard charts (Chart.js, bundled locally in `assets/js/vendor/`) were also rendered in that harness.
**Not verified:** your real Payment Hub, real Apache/cPanel hosting, PHP 7.4, real mobile browsers, and pixel-level fidelity to the original design in a real browser —
please run a KES 1 end-to-end payment and click through the site (desktop + phone) once after installing.

## 13. Troubleshooting
| Symptom | Fix |
|---|---|
| **500 error** right after upload | Your host may not allow `Options` in `.htaccess` — delete the line `Options -Indexes -MultiViews`. Also check the PHP version (7.4+). |
| Category / document pages show **404** | `mod_rewrite` or `AllowOverride All` is off. Turn off **Settings → SEO → Clean URLs** (or ask the host to enable rewriting). |
| Upload says the file is too large | Raise `upload_max_filesize` and `post_max_size` (cPanel → MultiPHP INI Editor) and **Settings → Documents → Maximum file size**. |
| Preview not generated | See §6. Check *Admin → edit document → Preview*; upload preview images manually, or ask the host to install `poppler-utils` / LibreOffice. |
| Payment stays **pending** | Check the Hub URL / Client ID / Client secret (Settings → Payment Hub → *Test connection*), that the Hub can reach `/ajax/webhook.php`, the webhook secret, and *Admin → Payments → Webhook events*. The status check (server-to-server) also confirms payments without the webhook. |
| No emails | Receipts and notices use PHP `mail()`; many hosts restrict it. Nothing else depends on email. |
| Behind Cloudflare / a CDN | Do **not** cache HTML pages (pages carry per-visitor CSRF tokens). Set `TRUST_PROXY` to `true` in `includes/config.php` so IPs and HTTPS are detected correctly. |
| Blank page / need details | Set `APP_ENV` to `'development'` temporarily, or read `private_documents/error.log`. |

## 14. Homepage extras
Admin → **Homepage** controls the hero text, popular-search pills, which sections appear, an optional **announcement banner**, testimonials (the block stays hidden until you add real ones) and the call-to-action.
