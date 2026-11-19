# Point Of Sale

A web-based Point of Sale (POS) and inventory management application built with Laravel 12, Tailwind CSS, and Vite.

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)

## Features

- **Cashier & Sales:** Interactive POS cashier workflow, sales history, itemized sale details, and PDF receipt/report generation (`laravel-dompdf` / `laravel-snappy`) with barcode support (`milon/barcode`).
- **Inventory & Stock Control:** Stock management by item category and rack, stock movement logs, stock withdrawals (`stock/taken`), stock reporting, and per-item or bulk stock reset.
- **Purchasing & Suppliers:** Supplier directory, supplier stock tracking, and purchase order management.
- **Customers & Receivables:** Customer management with categories and debt/installment payment recording.
- **Multi-Store & Access Control:** Store, region, role, and user management with admin and authenticated cashier routes.

## Prerequisites & Quick Start

- **PHP `>= 8.2`** (with SQLite/PDO extensions)
- **Composer**
- **Node.js & npm**

1. **Install dependencies:**

   ```bash
   composer install
   npm install
   ```

2. **Configure environment:**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Set up the database (defaults to SQLite):**

   ```bash
   touch database/database.sqlite
   php artisan migrate --seed
   ```

4. **Start the development servers:**

   ```bash
   composer run dev
   ```

   This runs `php artisan serve`, `php artisan queue:listen --tries=1`, and `npm run dev` concurrently.

## Usage & Configuration

Key environment variables from `.env.example`:

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `"Point Of Sale"` | Application display name |
| `APP_ENV` | `local` | Runtime environment (`local`, `production`) |
| `APP_DEBUG` | `true` | Enable/disable debug error output |
| `APP_URL` | `http://localhost` | Base application URL |
| `APP_LOCALE` | `id` | Default application locale |
| `DB_CONNECTION` | `sqlite` | Database driver (`sqlite`, `mysql`, `pgsql`) |
| `SESSION_DRIVER` | `file` | Session storage backend |

<details>
<summary>Docker development setup</summary>

A development compose file (`docker-compose.dev.yml`) is included for running the PHP app behind Nginx on port `9094` (using external network `bisnis-net`):

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

</details>

## Development & Testing

- **Run tests:**

  ```bash
  composer run test
  ```

- **Build frontend assets:**

  ```bash
  npm run build
  ```

- **Format PHP code (Laravel Pint):**

  ```bash
  ./vendor/bin/pint
  ```

## Contributing

1. Fork the repository and create a feature branch.
2. Make your changes and verify tests pass with `composer run test`.
3. Open a pull request with a clear description of the change.

## License

This project is licensed under the [MIT License](composer.json).
