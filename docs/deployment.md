# Production Deployment Checklist

When you see a **500 error** in production, the first step is to get the actual error.

## 1. Find the real error

On the server, open the Laravel log:

```bash
tail -100 storage/logs/laravel.log
```

Or temporarily set in `.env` (then remove after debugging):

```
APP_DEBUG=true
LOG_LEVEL=debug
```

Reload the page and check the browser or log again. **Set `APP_DEBUG=false` again** when done.

---

## 2. Production checklist

### Environment

- [ ] `.env` exists on the server (do not deploy `.env.example` as `.env`; create a real `.env` from it).
- [ ] `APP_KEY` is set. If missing, run on server: `php artisan key:generate`.
- [ ] `APP_ENV=production` and `APP_DEBUG=false`.
- [ ] `APP_URL` matches your site (e.g. `https://store.layla-kitchen.com` or `https://store.layla-kitchen.com/public` if you keep `/public` in the URL).
- [ ] `DB_*` (host, database, username, password) are correct and the DB is reachable from the server.

### Permissions

- [ ] `storage` and `bootstrap/cache` are writable by the web server:

  ```bash
  chmod -R 775 storage bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache   # or your web server user
  ```

### Dependencies and caches

- [ ] Composer: `composer install --no-dev --optimize-autoloader`
- [ ] Laravel caches (run from project root):  
  `php artisan config:cache`  
  `php artisan route:cache`  
  `php artisan view:cache`

### Web server

- [ ] Document root should point to the **`public`** directory so the site is `https://store.layla-kitchen.com/` and not `https://store.layla-kitchen.com/public/`.
- [ ] If you must use `.../public/` in the URL, set `APP_URL=https://store.layla-kitchen.com/public` and ensure `ASSET_URL` is set the same if you use Vite/mix assets.

### Common 500 causes

| Cause | Fix |
|-------|-----|
| Missing or wrong `APP_KEY` | `php artisan key:generate` and set in `.env` |
| No `.env` or wrong path | Create/copy `.env`, ensure it’s in project root |
| DB connection failed | Check `DB_*` in `.env`, firewall, and DB user permissions |
| Storage/cache not writable | Fix permissions on `storage` and `bootstrap/cache` |
| PHP version | Laravel 11 needs PHP 8.2+ |
| Missing extensions | Install required PHP extensions (e.g. pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json) |

---

## 3. After fixing

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then reload the site and check `storage/logs/laravel.log` again if anything fails.
