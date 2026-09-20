# LG Transparency Spend

A dashboard for UK local-government spending transparency. It ingests councils'
published "payments over £500/£250" spend files and contract registers, matches
technology suppliers, classifies what was bought, and presents the results as
browsable council, supplier and analysis pages.

- **Public to browse**, with a login only for editors/admins who manage sources.
- Built on the [GOV.UK Design System](https://design-system.service.gov.uk/).
- Ships with the **source catalogue and matching rules**, but **no spending data** —
  you run the importer to fetch the public data yourself (see [Loading data](#4-load-the-data)).

> **Note on data:** this repository contains no harvested spending data and no
> record of any Freedom of Information requests. The included source catalogue
> lists only councils' own published, externally-hosted URLs.

## Requirements

- **PHP 8.1 or newer** (the code uses constructor property promotion and arrow
  functions; PHP 7.x will not parse it).
- **MariaDB 10.4+ or MySQL 8+**.
- A web server (Apache or nginx) for production, or PHP's built-in server for
  local development. The `ext-pdo_mysql`, `ext-mbstring` and `ext-zip`
  extensions are required (the last for reading `.xlsx` source files).

## Setup

### 1. Get the code

```bash
git clone <your-fork-url> lg-transparency-spend
cd lg-transparency-spend
```

### 2. Create the database and load the schema + seed

```bash
mysql -u root -p -e "CREATE DATABASE lgts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p lgts < db/schema.sql
cat db/seed/*.sql | mysql -u root -p lgts
```

`db/schema.sql` creates all tables (the `ct_spend_display` view is defined last,
after the table it depends on). The seed loads the config/logic tables — council
config, the source-URL catalogue, supplier match patterns, service keywords and
the product taxonomy (~12,200 rows). The harvested-data tables are created empty.

### 3. Configure the database connection

Copy one of the credential templates and fill it in:

```bash
cp contracts/config.local.example.php contracts/config.local.php
# edit contracts/config.local.php — set DB_HOST, DB_NAME, DB_USER, DB_PASS
```

Alternatively, set the same values as environment variables (see `.env.example`);
environment variables take precedence over `config.local.php`. Neither file is
committed — both are covered by `.gitignore`.

### 4. Create an admin user

```bash
php contracts/tools/create_user.php you@example.com "Your Name" admin
```

You'll be prompted for a password (stored as a bcrypt hash). Re-running with the
same email updates the account.

### 5. Serve the app

Internal links assume the app is mounted at `/contracts/`. For local development,
serve the repository root and browse to the `/contracts/` path:

```bash
php -S 127.0.0.1:8000
# then open http://127.0.0.1:8000/contracts/
```

For production, point your web server so the `contracts/` directory is served at
`/contracts/` and the bundled assets resolve at `/contracts/assets/`.

### 6. Load the data

The importer fetches each active source in the catalogue and stores matched
technology payments:

```bash
php8.1 contracts/run_all_import.php            # import all active councils
php8.1 contracts/run_all_import.php --dry-run  # list what would be imported
```

Then build the summary tables that the dashboard reads from:

```bash
php8.1 contracts/tools/rebuild_summaries.php
```

Useful admin tools live in `contracts/tools/` (source management, contract-register
import, product derivation, a regression harness and more).

## What's included / not included

| Included | Not included |
|---|---|
| Web app + importer engine | Any harvested spending or contract data |
| Database schema (all tables + view) | Populated data tables (they load empty) |
| Config/logic seed (sources, patterns, taxonomy) | Self-hosted source files or their URLs |
| Vendored GOV.UK Frontend CSS | Secrets, credentials, user accounts |

## Licensing

- **Code** in this repository is released under the **MIT Licence** — see
  [`LICENSE`](LICENSE). This follows the UK Government Service Manual guidance on
  [making source code open and reusable](https://www.gov.uk/service-manual/technology/making-source-code-open-and-reusable).
- **The underlying spending data** is public-sector information published by local
  authorities and is available under the
  [Open Government Licence v3.0](https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/).
  Attribute the relevant authority when you reuse it.
- **GOV.UK Frontend** (the bundled stylesheet in `contracts/assets/`) is © Crown
  Copyright and included under its own MIT licence — see
  [`contracts/assets/GOVUK-FRONTEND-LICENCE.txt`](contracts/assets/GOVUK-FRONTEND-LICENCE.txt).
