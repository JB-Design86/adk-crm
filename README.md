# ADK CRM

CRM der ADK – Akademie für digitale Kompetenz. Stufe 1: Leads und Akquise.

- Lastenheft: [docs/ADK_CRM_Lastenheft_v0.2.md](docs/ADK_CRM_Lastenheft_v0.2.md)
- Betrieb und Auslieferung: [docs/BETRIEB.md](docs/BETRIEB.md)
- Abnahme Stufe 1: [docs/STUFE1_ABNAHME.md](docs/STUFE1_ABNAHME.md)

## Lokale Entwicklung

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Tests: `php vendor/bin/pest`
