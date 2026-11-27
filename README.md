# Country Quiz

Practice where the countries of the world are. The page shows a country's outline on a map,
and you either pick the right name from 5 nearby countries or type it (Swedish or English).

- Plain PHP 8.4 (works on 8.1+), plain JavaScript, no Composer or npm packages.
- No database: the score lives in the PHP session.
- Areas: World, Europe, Asia, Africa, Americas, North America, South America, Oceania.
- Works on phones.

## Folder layout

Everything lives in one folder, so the whole repository can be copied into a sub folder of
the web root (e.g. `/web/countryquiz`).

| Path | What it is |
| --- | --- |
| `index.php` | The quiz page |
| `api.php` | JSON API used by the page |
| `assets/` | JavaScript and CSS |
| `src/` | PHP classes: `CountryRepository`, `Quiz`, `NameMatcher` |
| `data/countries.json` | Simplified country outlines, names and nearest countries (generated) |
| `tools/build-data.php` | Regenerates `data/countries.json` from Natural Earth (command line only) |

`src/`, `data/` and `tools/` are not meant to be opened in a browser. On Apache the
`.htaccess` files block them. On nginx (Web Station's default) nothing in them is sensitive:
`src/bootstrap.php` and `tools/build-data.php` answer 404 when opened directly, the class files
only define classes, and the country data is public domain.

## Run locally

```bash
php -S localhost:8080
```

Then open http://localhost:8080.

## Run on a Synology NAS (Web Station)

1. Copy the whole repository to a sub folder of the web root, e.g. `/web/countryquiz`
   (via File Station, git or rsync).
2. In **Web Station → Script Language Settings**, make sure the PHP 8.4 profile is used for the
   default web portal (or create a portal for the folder with that profile).
   The `json` and `session` extensions are on by default; `zlib` (optional) gzips the map data.
3. Open `http://<your-nas>/countryquiz/`.

## Deploy from Windows

`deploy.ps1` copies the files the site needs to `\\10.8.0.1\web\world` with robocopy
(built into Windows, nothing to install). `tools/`, `README.md` and `.git` are not copied.

```powershell
.\deploy.ps1            # deploy
.\deploy.ps1 -WhatIf    # only show what would change
.\deploy.ps1 -Target \\10.8.0.1\web\world-test   # another folder
```

Or double-click `deploy.cmd`. The folders `assets/`, `src/` and `data/` are mirrored, so files
removed locally are also removed on the NAS; nothing else in the target folder is touched.

## Updating the map data

The data comes from [Natural Earth](https://www.naturalearthdata.com/) (1:50m Admin 0 countries, public domain).
To regenerate it:

```bash
curl -L -o ne_50m_admin_0_countries.geojson \
  https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_admin_0_countries.geojson
php tools/build-data.php ne_50m_admin_0_countries.geojson
```

Which territories are part of the quiz, and extra accepted names (e.g. "UK", "DR Congo"),
are configured at the top of `tools/build-data.php`.
