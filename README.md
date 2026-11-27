# Country Quiz

Practice where the countries of the world are. The page shows a country's outline on a map,
and you either pick the right name from 5 nearby countries or type it (Swedish or English).

- Plain PHP 8.4 (works on 8.1+), plain JavaScript, no Composer or npm packages.
- No database: the score lives in the PHP session.
- Areas: World, Europe, Asia, Africa, Americas, North America, South America, Oceania.
- Works on phones.

## Folder layout

| Path | What it is |
| --- | --- |
| `public/` | The web root: `index.php` (page), `api.php` (JSON API), `assets/` (JS and CSS) |
| `src/` | PHP classes: `CountryRepository`, `Quiz`, `NameMatcher` |
| `data/countries.json` | Simplified country outlines, names and nearest countries (generated) |
| `tools/build-data.php` | Regenerates `data/countries.json` from Natural Earth |

Only `public/` should be reachable from the web.

## Run locally

```bash
php -S localhost:8080 -t public
```

Then open http://localhost:8080.

## Run on a Synology NAS (Web Station)

1. Copy the whole repository to e.g. `/web/countryquiz` (via File Station, git or rsync).
2. In **Web Station → Script Language Settings**, make sure a PHP 8.4 profile exists.
   The `json` and `session` extensions are on by default; `zlib` (optional) gzips the map data.
3. In **Web Station → Web Service Portal**, create a portal (name-based or port-based) whose
   document root is the `public` folder, e.g. `/web/countryquiz/public`, using the PHP 8.4 profile.
4. Open the portal's address in a browser.

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
