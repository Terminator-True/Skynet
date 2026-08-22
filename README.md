# skynet

Personal AI assistant: Laravel 12 + Inertia + Vue, local Ollama, Google Gmail/Calendar read-only.

## Rotating APP_KEY

Google OAuth tokens (`google_tokens.access_token` / `refresh_token`) are stored with Laravel's
`encrypted` cast under `APP_KEY`. **Rotating `APP_KEY` bricks every stored token** with a loud
`DecryptException`.

Before rotating:

```bash
cp .env .env.backup-$(date +%F)   # back up the current key
php artisan key:generate
```

Then reconnect Google via `/connect` to re-encrypt tokens under the new key.
