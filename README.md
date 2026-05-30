# Printers Dashboard

Self-hosted PHP dashboard for monitoring IPP printers (status, ink levels).
Polls each printer live in parallel, cached server-side for 30 s.

## Setup

```bash
composer install
cp .env.example .env   # add your printers
php -S 0.0.0.0:8080
```

Open <http://localhost:8080/>.

## Configuration

Everything lives in `.env`. See `.env.example` for the full template. Add
printers as numbered pairs:

```dotenv
PRINTER_1_NAME=Lab Color
PRINTER_1_IP=10.0.0.50
```
