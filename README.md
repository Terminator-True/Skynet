# Skynet — Local, Privacy-First Personal AI Assistant

A personal conversational AI assistant that runs **100% on your own hardware**. It reads your Gmail and Calendar (read-only), tracks Amazon packages, searches the web, recalls an Obsidian knowledge base, remembers long-term preferences, and talks to you by voice — all processed locally via Ollama. **No email, calendar, or conversation content ever leaves your machine** or reaches a third-party AI API.

## Why it's private

- The LLM (Ollama, e.g. Qwen 2.5 14B) runs on your hardware.
- Speech-to-text (whisper.wasm) and text-to-speech (piper) run in the browser, fully local.
- Gmail/Calendar data is only processed locally; web search sends only the isolated query.
- Google OAuth tokens are stored encrypted (Laravel `encrypted` cast).

## Features

| Area | What it does |
|------|--------------|
| Chat | Natural-language, multi-turn conversation (stateful `POST /chat`), rendered as safe HTML (Markdown + DOMPurify) in an AEGIS-style HUD. |
| Gmail | `buscar_correos`, `leer_correo` — search and read mail (read-only). |
| Calendar | `listar_eventos_calendario` — upcoming events (read-only). |
| Amazon tracking | `extraer_tracking_amazon` — extract tracking from Amazon confirmation emails. |
| Web search | `buscar_web` — keyless search (DuckDuckGo/Wikipedia). |
| Knowledge base | `buscar_notas` + `guardar_nota` — recall and save notes to your Obsidian vault; the agent offers to save what you learned. |
| Long-term memory | `recordar_preferencia` — remembers preferences between sessions via local embeddings. |
| Voice | `/voice` — push-to-talk and hands-free wake-word ("Oye Skynet"/"Hola Skynet"), local whisper STT + piper TTS (Spanish). |
| Proactive notifications | Laravel Scheduler + queue + Reverb push for calendar/package/email events. |
| UI | AEGIS HUD theme: ember/cyan, animated orb reacting to state (idle/processing/listening/speaking), responsive, bounded-scroll chat box. |

## Tech stack

| Component | Tech |
|-----------|------|
| Backend | Laravel 13 (PHP 8.3) |
| Frontend | Vue 3 (TypeScript) + Inertia + Tailwind CSS v4 |
| LLM | Ollama (local), tool-calling via function definitions |
| Database | MySQL |
| Realtime | Laravel Reverb / Echo |
| STT / TTS | `@timur00kh/whisper.wasm` + `@mintplex-labs/piper-tts-web` (local) |
| Google APIs | Gmail + Calendar (read-only scopes) |

## Requirements

- PHP 8.3+, Composer, Node 20+, MySQL
- [Ollama](https://ollama.com) with a tool-calling model (e.g. `qwen2.5:14b`)
- A browser with WebAssembly support (for voice)
- A Google Cloud project with Gmail + Calendar APIs enabled (for Google features)

## Quick start

```bash
# 1. Install dependencies
composer install
npm install            # runs patch-package (piper-tts-web is patched)

# 2. Configure
cp .env.example .env
php artisan key:generate
#   ...set DB_*, OLLAMA*, GOOGLE_* (see Configuration)

# 3. Provision the local voice models (gitignored) — required for /voice
bash scripts/download-models.sh

# 4. Run
php artisan migrate --seed
php artisan serve      # http://localhost:8000
npm run dev            # Vite dev server (separate terminal)

# 5. Open /chat and /voice
```

> **Voice routing note:** `onnxruntime-web` resolves model paths relative to the JS module origin (the Vite dev server), so `npm run dev` must be running and the dev server proxies `/models` to Laravel. Access the app via the Laravel URL (`http://localhost:8000`).

## Google OAuth

Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in `.env`. In Google Cloud Console, add your redirect URI as an authorized redirect. Then open `/connect` to authorize (Gmail + Calendar read-only scopes). No OAuth verification is required while the project is in "Testing" mode with a single authorized user.

## Configuration

Key environment variables (see `.env.example`):

| Variable | Purpose |
|----------|---------|
| `OLLAMA_BASE_URL` | Ollama server URL |
| `OLLAMA_MODEL` | Model name (tool-calling) |
| `OLLAMA_EMBED_MODEL` | Embedding model for memory/notes (e.g. `nomic-embed-text`) |
| `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` | Google OAuth |
| `NOTES_VAULT_PATH` | Path to the Obsidian vault (default `main_obsidian/`) |
| `REVERB_*` | Reverb (realtime notifications) |

## Voice models

The local STT/TTS models are **gitignored** (`public/models/`) and fetched once by:

```bash
bash scripts/download-models.sh
```

This downloads whisper `ggml-tiny.bin`, the piper Spanish voice, and the onnxruntime runtime (`.mjs` + `.wasm`). Run it after a fresh clone, or `/voice` will fail to load.

## Project structure

```
app/Tools/          Tool definitions (function calling)
app/Services/       ChatOrchestrator, ConversationService, Notes, Memory
routes/web.php      Inertia pages, /models serving, Google OAuth
routes/api.php      POST /chat, GET /chat/history
resources/js/pages/ Chat.vue, VoiceChat.vue (AEGIS HUD)
resources/js/lib/   voice (whisper/piper), assistant (orb), markdown
scripts/            download-models.sh (voice model provisioning)
```

## Known gotchas

### Rotating `APP_KEY`

Google OAuth tokens (`google_tokens.access_token` / `refresh_token`) are stored with Laravel's `encrypted` cast under `APP_KEY`. **Rotating `APP_KEY` bricks every stored token** with a loud `DecryptException`.

Before rotating:

```bash
cp .env .env.backup-$(date +%F)   # back up the current key
php artisan key:generate
```

Then reconnect Google via `/connect` to re-encrypt tokens under the new key.

### Voice models after fresh clone

Run `scripts/download-models.sh` or `/voice` (whisper/piper) will 404 on `/models/*`.