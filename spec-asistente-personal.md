# Especificación del Proyecto: Asistente Personal con IA Local

## 1. Resumen ejecutivo

Construir un asistente personal conversacional (interfaz de lenguaje natural) que corra de forma **local y privada**, capaz de:
- Leer y consultar el correo de Gmail del usuario.
- Trackear paquetes de Amazon a partir de los correos de confirmación/envío.
- Avisar de eventos importantes en Google Calendar.
- Responder preguntas generales usando búsqueda web.
- Escalar con nuevas funcionalidades (tools) sin rediseñar el core.

**Prioridad de diseño:** privacidad máxima. El procesamiento del LLM ocurre en hardware local (RTX 3060 12GB) vía Ollama. Ningún contenido de correo/calendario se envía a APIs de terceros de IA.

**Coste objetivo:** $0/mes (o prácticamente $0), usando exclusivamente tiers gratuitos y cómputo local.

---

## 2. Objetivos y no-objetivos

### Objetivos (MVP)
- Chat en lenguaje natural funcional end-to-end.
- Modelo LLM local (Ollama) con soporte de tool calling.
- Integración de solo lectura con Gmail (búsqueda y lectura de correos).
- Integración de solo lectura con Google Calendar (listado de eventos).
- Extracción automática de tracking de paquetes de Amazon desde correos.
- Búsqueda web como tool adicional para preguntas generales.
- Sistema de notificaciones proactivas (background job) para eventos y paquetes relevantes.
- Arquitectura de "tools" extensible para añadir funcionalidades futuras sin refactor mayor.

### No-objetivos (fuera de alcance del MVP)
- Multi-usuario / multi-tenant (proyecto personal, un solo usuario).
- Escritura/modificación de correos o eventos (solo lectura en esta fase).
- App móvil nativa (solo web app).
- Integración directa con la API de Amazon (no existe API pública adecuada; se resuelve vía parsing de correos).
- Fine-tuning de modelos.

---

## 3. Stack tecnológico

| Componente | Tecnología | Motivo |
|---|---|---|
| Backend | Laravel (PHP) | Stack principal del desarrollador; Scheduler y Queues nativos encajan con el caso de uso |
| Frontend | Vue.js (vía Inertia.js) | Stack principal del desarrollador; evita duplicar API REST |
| LLM | Ollama + Qwen2.5 14B Instruct (Q4_K_M) | Corre en RTX 3060 12GB; buen soporte de function calling en open-source |
| LLM alternativo (fallback si 14B es lento) | Llama 3.1 8B Instruct | Más rápido, function calling oficial bien documentado |
| Base de datos | MySQL o PostgreSQL | Estándar en Laravel |
| Colas / Jobs | Laravel Queues (driver `database` o `redis`) | Para notificaciones proactivas asíncronas |
| Scheduler | Laravel Scheduler (cron) | Polling periódico de Gmail/Calendar |
| WebSockets / tiempo real | Laravel Reverb | Notificar al frontend sin refrescar |
| Google APIs | `google/apiclient` (paquete oficial PHP) | Gmail API + Calendar API |
| Búsqueda web | DuckDuckGo Instant Answer API (gratis, sin key) | Alternativa: Brave Search API (tier gratuito) |
| Tracking de paquetes | Parsing con LLM local + (opcional) AfterShip/17Track API | Sin API pública de Amazon disponible |

---

## 4. Arquitectura general

```
┌─────────────┐      ┌──────────────────────┐      ┌─────────────┐
│  Vue (SPA/  │◄────►│   Laravel Backend     │◄────►│   Ollama    │
│  Inertia)   │ HTTP/│  - API endpoints      │ HTTP │  (local)    │
│             │ WS   │  - Tool orchestrator  │      │  Qwen2.5 14B│
└─────────────┘      │  - Scheduler          │      └─────────────┘
                      │  - Queue workers      │
                      └──────┬───────┬────────┘
                             │       │
                 ┌───────────┘       └───────────┐
                 ▼                                ▼
        ┌─────────────────┐              ┌──────────────────┐
        │  Google APIs     │              │  DuckDuckGo /     │
        │  (Gmail, Cal.)   │              │  Brave Search API │
        └─────────────────┘              └──────────────────┘
```

**Flujo de una consulta del usuario:**
1. Usuario escribe un mensaje en el chat (Vue).
2. Laravel recibe el mensaje, lo envía a Ollama junto con las definiciones de tools disponibles.
3. Ollama decide si necesita llamar una tool (ej: `buscar_correos`, `listar_eventos`, `buscar_web`).
4. Laravel ejecuta la tool solicitada (llamada real a Gmail API / Calendar API / búsqueda web).
5. El resultado se devuelve al modelo, que genera la respuesta final en lenguaje natural.
6. Laravel devuelve la respuesta al frontend (idealmente vía streaming/SSE).

**Flujo de notificaciones proactivas:**
1. Laravel Scheduler dispara un job cada N minutos.
2. El job consulta Gmail (correos nuevos) y Calendar (eventos próximos).
3. Si detecta algo relevante (regla simple o evaluación con el LLM), crea una notificación en BD.
4. Laravel Reverb empuja la notificación al frontend en tiempo real.

---

## 5. Modelo de datos (borrador inicial)

- **users**: id, name, email, google_oauth_tokens (encrypted), created_at.
- **conversations**: id, user_id, created_at.
- **messages**: id, conversation_id, role (user/assistant/tool), content, tool_calls (json), created_at.
- **tracked_packages**: id, user_id, source_email_id, carrier, tracking_number, product_name, status, estimated_delivery, last_checked_at.
- **notifications**: id, user_id, type (calendar_event/package_update/email_important), payload (json), read_at, created_at.
- **google_tokens**: id, user_id, access_token (encrypted), refresh_token (encrypted), expires_at, scopes.

---

## 6. Definición de Tools (function calling)

| Tool | Descripción | Input | Output |
|---|---|---|---|
| `buscar_correos` | Busca correos en Gmail por query (sintaxis Gmail search) | `query: string, max_results: int` | Lista de correos (asunto, remitente, snippet, fecha) |
| `leer_correo` | Obtiene el contenido completo de un correo por ID | `message_id: string` | Cuerpo completo del correo |
| `listar_eventos_calendario` | Lista eventos próximos | `desde: datetime, hasta: datetime` | Lista de eventos (título, hora, ubicación) |
| `buscar_web` | Búsqueda general en internet | `query: string` | Resultados de búsqueda (título, snippet, url) |
| `extraer_tracking_amazon` | Extrae datos de tracking de un correo de Amazon | `message_id: string` | `{carrier, tracking_number, product_name, status}` |
| `consultar_estado_paquete` | Consulta estado actual de un tracking number (API externa) | `tracking_number: string, carrier: string` | Estado actualizado del envío |

> Diseño clave para escalabilidad: cada nueva funcionalidad futura (Notion, Spotify, tareas, smart home, etc.) se añade como una nueva entrada en esta tabla + su implementación, sin tocar el orquestador central.

---

## 7. Fases de desarrollo

### Fase 0 — Esqueleto y validación de tool calling
- Proyecto Laravel + Inertia + Vue inicializado.
- Endpoint de chat básico conectado a Ollama (sin tools reales aún).
- 2-3 tools "dummy" para validar fiabilidad del tool calling de Qwen2.5 14B.
- **Criterio de aceptación:** el modelo llama correctamente las tools dummy en al menos 8/10 pruebas variadas.

### Fase 1 — Autenticación Google
- Flujo OAuth2 completo (Gmail readonly + Calendar readonly scopes).
- Almacenamiento seguro de tokens (encriptados) con refresh automático.
- **Criterio de aceptación:** login exitoso, tokens persistidos y renovados sin intervención manual.

### Fase 2 — Tool: Calendar
- Implementar `listar_eventos_calendario`.
- **Criterio de aceptación:** preguntas como "¿qué tengo hoy?" devuelven eventos reales y correctos.

### Fase 3 — Tool: Gmail + parsing de Amazon
- Implementar `buscar_correos`, `leer_correo`.
- Implementar `extraer_tracking_amazon` (extracción vía LLM sobre el contenido del correo).
- Tabla `tracked_packages` poblada automáticamente.
- **Criterio de aceptación:** correos de confirmación de Amazon generan registros de paquete correctos (producto + tracking number).

### Fase 4 — Búsqueda web
- Implementar `buscar_web` vía DuckDuckGo Instant Answer API.
- **Criterio de aceptación:** preguntas generales no relacionadas con correo/calendario se responden correctamente.

### Fase 5 — Notificaciones proactivas
- Laravel Scheduler + Queue job que revisa correos/eventos periódicamente.
- Reglas iniciales simples (ej: evento en próxima hora, correo de Amazon con cambio de estado).
- Laravel Reverb para push en tiempo real al frontend.
- **Criterio de aceptación:** el usuario recibe notificación en la app sin recargar, dentro de los N minutos del evento detectado.

### Fase 6+ — Escalabilidad futura (backlog, no MVP)
- Nuevas tools (Notion, tareas, Spotify, etc.).
- Memoria a largo plazo (embeddings) para preferencias del usuario entre sesiones.
- Posible entrega de notificaciones vía Telegram/email además de la web app.

#### 6.1 Integración con dispositivos Google Home (smart home)

**Contexto técnico:** las "Home APIs" oficiales de Google (Device API, Structure API, Automation API) son actualmente SDKs móviles nativos (Android en beta pública, iOS más adelante) — no exponen un endpoint REST que un backend de servidor (Laravel) pueda llamar directamente. La alternativa oficial de servidor, la Nest SDM API, solo cubre productos Nest, no el ecosistema Google Home en general.

**Solución recomendada:** usar **Home Assistant** como puente local.
- Se instala en la propia red local (ej. Raspberry Pi o el mismo PC).
- Tiene integración madura con Google Home, Matter y la mayoría de marcas de dispositivos inteligentes.
- Expone una **API REST y WebSocket local**, ideal para que Laravel la consuma.
- Nueva tool a definir: `controlar_dispositivo_home(device_id, accion)` → llama a `http://homeassistant.local:8123/api/...`.
- Mantiene la premisa de privacidad: todo el control permanece en la red local, sin depender de que Google exponga la API a servidores externos.

#### 6.2 Interacción por voz

Ver sección 11 (Interacción por voz) para el análisis completo y la arquitectura recomendada.

---

## 8. Consideraciones de privacidad y seguridad

- Los tokens OAuth de Google deben almacenarse **encriptados** (Laravel `encrypted` cast).
- El contenido de correos/calendario **nunca sale del entorno local** hacia APIs de terceros de IA; solo Ollama (local) procesa ese contenido.
- La búsqueda web solo envía la query puntual, sin contexto personal adjunto.
- Scopes de Google solicitados: mínimo necesario (`gmail.readonly`, `calendar.readonly`).
- Proyecto en modo "Testing" en Google Cloud Console (no requiere verificación de Google mientras el usuario sea el único autorizado).

---

## 9. Riesgos conocidos

- **Fiabilidad del tool calling en modelo local de 14B**: puede requerir ajuste fino de prompts o reintentos; validar en Fase 0 antes de avanzar.
- **Cambios en formato de correos de Amazon**: mitigado usando extracción vía LLM (más robusto que regex fijo), pero no infalible.
- **Rendimiento de Qwen2.5 14B en RTX 3060 12GB**: verificar tiempos de respuesta aceptables; tener Llama 3.1 8B como fallback si es necesario.
- **Cuotas de Gmail/Calendar API**: uso personal está muy por debajo de los límites estándar, pero vigilar si se añade polling muy frecuente.

---

## 11. Interacción por voz (backlog futuro)

### Por qué NO es viable usar los altavoces Google Home existentes

Google descontinuó **Conversational Actions** (el mecanismo que permitía a terceros crear experiencias de voz personalizadas dentro de Google Assistant) el 13 de junio de 2023. Desde entonces, no existe una vía soportada por Google para que un desarrollador externo inyecte un asistente conversacional propio en un altavoz/pantalla de Google Home existente. Lo único que sigue funcionando en esos dispositivos es control de smart home estándar (encender luces, etc.), no conversación libre con un backend propio.

**Conclusión:** los dispositivos Google Home actuales del usuario **no pueden usarse como interfaz de voz para este asistente personalizado**. Son útiles únicamente como dispositivos smart home controlables vía Home Assistant (ver sección 6.1), pero no como micrófono/altavoz de entrada-salida del asistente.

### Solución recomendada: hardware de voz dedicado, 100% local

Montar un "satélite de voz" propio (ej. Raspberry Pi 4/5 con micrófono USB y altavoz, o incluso un mini-PC), con el siguiente pipeline, totalmente coherente con la arquitectura local-first ya definida:

| Etapa | Tecnología recomendada | Notas |
|---|---|---|
| Wake word (palabra de activación) | openWakeWord o Porcupine (Picovoice) | Corre en el propio dispositivo satélite, bajo consumo |
| Speech-to-Text | Whisper (local, ej. `faster-whisper` o `whisper.cpp`) | Puede correr en el satélite o en la RTX 3060 si hay red local rápida |
| Orquestación / LLM | Backend Laravel + Ollama (ya definidos) | Reutiliza exactamente el mismo pipeline de tools del chat de texto |
| Text-to-Speech | Piper (TTS local, ligero y rápido) | Voces en español disponibles, corre bien incluso en Raspberry Pi |
| Transporte | WebSocket/HTTP hacia el backend Laravel en la red local | Mismo patrón que Laravel Reverb ya contemplado |

**Ventaja clave:** este pipeline es 100% local (nada sale a servidores de Google/Amazon/terceros para el procesamiento de voz), reutiliza el mismo orquestador y las mismas tools ya construidas para el chat de texto (Gmail, Calendar, paquetes, smart home), y es el enfoque que sigue la comunidad de Home Assistant para voz privada.

**Fases sugeridas para esta funcionalidad (dentro del backlog 6+):**
1. Prototipo de STT+TTS local en el PC de desarrollo (validar latencia y calidad en español).
2. Montaje del satélite de voz (Raspberry Pi + mic + altavoz) con wake word.
3. Conexión del satélite al backend Laravel vía WebSocket, reutilizando el endpoint de chat existente.
4. Ajuste de latencia end-to-end (wake word → STT → LLM+tools → TTS → audio) para que la experiencia sea fluida.

---

## 12. Preguntas abiertas para el agente/desarrollador

- BBDD: Mysql
- Inertia.Js
- Polling notificaciones proactivas: 15 min
- Se necesita UI para trackear paquetes desarrollados o trackeados
