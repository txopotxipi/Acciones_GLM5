# INFORME_TECNICO.md — Auditoría, optimización y refactor de Cartera Pro GLM5

> Proyecto: `D:\Acciones_GLM5` (producción: https://txopo13.lovestoblog.com/Acciones_GML5/)
> Fecha: 2026-10-28 · Método: loop planificar → construir → probar → revisar → corregir, con **árbitro adversarial** cuyo único objetivo era romper el trabajo. Sin elogios: solo fallos, argumento técnico y decisión en cada punto.

## 1. Resumen ejecutivo

| Área | Cambios principales |
|---|---|
| Seguridad de transporte (PHP) | 12 llamadas cURL con `CURLOPT_SSL_VERIFYPEER => false` pasaron a verificación real vía helper `lib_http.php` con sonda automática de CA bundle del hosting y degradación controlada registrada en `error_log()`. |
| Autenticación de endpoints | `Sec-Fetch-Site: none`, el Referer con `strpos()` y el bypass por Host permitían suplantación trivial con curl. Ahora: same-origin estricto (`same-origin` exacto) o Referer con host parseado e igual (case-insensitive, sin puerto), más `INTERNAL_API_KEY` para cron/CLI. |
| SQL portable | `IF(cond, a, b)` de MySQL sustituido por `CASE WHEN … END` (válido en MySQL y SQLite) en `quick_update.php`, `cron_update.php` y `save_data.php`. |
| GET en `quick_update.php` | Se elimina `Access-Control-Allow-Origin: *` (contradecía al CORS restringido del `.htaccess`). |
| Tema claro | `header`/`header.scrolled` seguían con fondo oscuro y el título con gradiente oscuro; overrides `[data-theme="light"]` añadidos (también en ibex-banner, toast y fundido de tabla). Corregido bug propio: usar `background:` en el override del h1 reseteaba `background-clip: text` → sustituido por `background-image`. |
| Errores HTTP | Cuando la BD no responde, la app ahora devuelve 503+Retry-After (antes 200 con HTML de error, engañoso). |
| XSS | El diagnóstico reinyectaba `data-name` sin re-escapar (el navegador decodifica entidades al leer el atributo). Nuevo helper `esc()` en `script.js`. |
| Dependencias | CDN de Chart.js y su adapter flotaban a "latest": fijados a `chart.js@4.6.1` y `chartjs-adapter-date-fns@3.0.0` (+ bump del precache del service worker a v16). |
| Gestión | `gestionar_cartera.php` acepta `GESTION_PASSWORD` como hash bcrypt/argon (`password_verify`) o texto plano (comparación con `hash_equals`). |
| Deploy y limpieza | `config.php` restaurado a las credenciales reales de producción; artefactos temporales en `_qa/` e ignorados junto con `local_test.sqlite` y `config.local_test.php`. |

## 2. Rondas del árbitro adversarial (11 en total)

### Ronda 1 — Seguridad y robustez (antes del refactor)
1. **Cualquiera podía saltar la "protección" de los 3 endpoints PHP.** Con `curl -H "Sec-Fetch-Site: none"` el chequeo daba por buena la petición (aceptaba `none` y `same-site`), y con un `Referer` que incluyera tu dominio dentro (p. ej. `https://evil.com/aqui-https://txopo13.lovestoblog.com/`) el chequeo de `strpos()` también pasaba. En `quick_update.php` aún peor: si no pintaba ninguna cabecera, saltaba porque el host de la petición (`Host: txopo13.lovestoblog.com`) **siempre** contiene `lovestoblog.com` — el endpoint quedaba efectivamente abierto para escrituras de precios/históricos en caché y BD.
   → Solución: same-origin estricto (Fetch-Metadata o igualdad exacta de host parseado) + API key para cron/CLI, en `api.php`, `save_data.php`, `quick_update.php`.
2. **`CURLOPT_SSL_VERIFYPEER => false` en 12 llamadas** (api, cron, ibex_update, perplexity_report, quick_update): MITM posible entre el hosting y Yahoo/Groq/Perplexity.
   → Solución: nuevo `lib_http.php` que aplica verificación real detectando por sonda si el hosting dispone de CA bundle (en CGI de InfinityFree es un problema real): estricto por defecto, fallback documentado en el log, decisión cacheada 24 h en `private_cache/ssl_strict.flag` para no pagar la sonda en cada petición.

### Ronda 2 — Efectos colaterales y sintaxis (post-refactor)
El árbitro tumbó 4 modificaciones con errores que introduje yo mismo al aplicar el anterior:
1. Intento de "spread" inválido en el array literal de `curl_setopt_array` (`* ..ssl_options(),`): sintaxis rota detectada con `php -l`. En su lugar, el helper ahora es `aplicar_ssl($ch)` y se llama después del bloque principal de opciones.
2. Secuencia de escape `\1` en scripts de reemplazo escritos por mí: quedó como carácter de control `\x01` en ficheros. Detectado con `php -l`, corregido con limpieza de `\x01`.
3. `parse_url('scheme_host://...')` devolvía NULL porque el subrayado no es válido en esquema; se cambió al prefijo `http://`.
4. Constantes CURLE inexistentes en ciertas builds de PHP (`CURLE_SSL_NOTFOUND`, `CURLE_PEER_FAILED_VERIFICATION`); se pasaron a literales numéricos documentados.
Cada fallo se verificó de nuevo con `php -l` más pruebas HTTP de rojo a verde (ver sección 4).

### Ronda 3 — UX, rendimiento y ajustes finos
1. **El tema claro seguía rompiendo tras el primer fix:** el override del `h1` utilizaba el shorthand `background:`, lo que restablece `background-clip: text` a `border-box` y pintaba el gradiente como caja índigo en vez de texto. Corregido con `background-image` (captura `_qa/qa-light-header2.png`).
2. **Latencia añadida por la sonda SSL** en hosting CGI (por petición). Mitigada con flag de 24 h en disco (`private_cache/ssl_strict.flag`).
3. **Respuesta 200 en fallo de BD** (`config.php` con `die()` sin código) — el navegador mostraba "todo OK" con HTML crudo. Ahora 503 + `Retry-After: 300`.
4. **Gráfico con salto vertical al final** (p. ej. 50 k€ → 77 k€): adjudicado a datos sembrados del entorno de pruebas (history_json viejo vs. precio actual real de Yahoo), no a un bug de código; en producción la serie de `cache_data.json`/`portfolio_snapshots` se construye diaria y coherente. El árbitro lo dejó anotado para futura confirmación con datos de producción frescos.

### Ronda 4 — Superficie restante (sobre la entrega)
1. **CDN flotando a "latest"**: `https://cdn.jsdelivr.net/npm/chart.js` sin versión → un mayor 5.x de Chart.js rompería la app en silencio para todos los visitantes. Fijado a `chart.js@4.6.1/dist/chart.umd.min.js` y `chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js` en `index.php` y en el precache del `service-worker.js` (bump del caché a v16). Verificado: ambas URLs responden 200 y la página renderiza con `Chart.version === "4.6.1"` y cero errores de consola (captura `_qa/qa-chart-pinned.png`).
2. **Reinyección de `data-name` en `innerHTML`**: `index.php` escapa el nombre con `htmlspecialchars` server-side, pero el navegador decodifica entidades al leer el atributo; al reinyectar el texto en el innerHTML del diagnóstico (línea 990) el HTML crudo volvía. Añadido helper `esc()` en `script.js` y aplicado a la interpolación (`esc(o.name)` en vez de `o.name`). Test en página: `<img ...>` → `&lt;img ...&gt;` sin reintroducir etiquetas.
3. **`GESTION_PASSWORD` en texto plano** (deuda heredada): `gestionar_cartera.php` ahora admite dos formatos en `config.php`, sin migración forzada: hash bcrypt/argon2 (`$2y$…`) validado con `password_verify`, o texto plano comparado con `hash_equals` (protección against timing). El árbitro marca como pendiente que generes el hash (`php -r "echo password_hash('tu_clave', PASSWORD_DEFAULT);"`) y sustituyas la clave en `config.php`.
4. **Posible bug de caché (falso positivo)**: `"source":"cache"` con `cache_age` 563 s > TTL 120 s — no es bug: fuera del horario del IBEX el TTL se extiende a 7 días por diseño (`quick_update.php:130`). Anotado para no perseguirlo en el futuro.

### Ronda 5 — Despliegue y superficie pública
1. **El workflow de deploy empujaría artefactos de test al webroot público.** Revisado `exclude` de `.github/workflows/deploy.yml` contra el árbol real: se escapaban `_qa/**` (capturas, scripts de test), `.freebuff/`, `local_test.sqlite`, `config.local_test.php`, los tres backups (`index.backup.php`, `script.backup.js`, `style.backup.css` — el patrón `**/*.backup.*` solo cubre `X.backup.ext` con punto antes de la extensión final en algunos casos, y `**/*backup*` es más robusto) y los textos internos (`INFORME_TECNICO.md`, `tarea.txt`). En un directorio público sería material alcanzable por URL y, peor aún, un `.htaccess` que solo bloquea extensiones de backup, no nombres. → Añadidos los patrones `**/.freebuff/**`, `**/_qa/**`, `**/local_test.sqlite`, `**/config.local_test.php`, `**/*backup*`, `**/INFORME_TECNICO.md` y `**/tarea.txt` al `exclude`, y YAML validado con `yaml.safe_load`.
2. **Verificación del estado de producción (navegador real):** la página carga con 200, título correcto, 0 errores y 0 avisos de consola, service worker `v=15` activado y `Chart.version === "4.6.1"` (coincide con el "latest" vigente, así que nada rompe hoy). El SW sigue en v15: confirma que los entregables de esta sesión **aún no están sincronizados** — el despliegue ocurrirá con el próximo push a `main`.

### Ronda 6 — PWA instalable y código muerto
1. **El manifiesto PWA era un `data:` URI y la app no era instalable.** `index.php` generaba el manifiesto en PHP, lo convertía en `data:application/manifest+json;base64,…` y lo enlazaba así. Contra la spec: un data: URI no tiene base URL, así que `start_url`/`scope` relativos no se resuelven → datos inválidos → instalación fallida/fuera de alcance (el comentario del código lo justificaba como "inmune a cortafuegos de hosting", pero es un recurso same-origin normal como cualquier otro `.php` del sitio). Además había **duplicación**: el mismo contenido existía dos veces (`manifest.json` huérfano en raíz + `manifest.php`). → Solución: `<link rel="manifest" href="manifest.php?v=4.0">` en `index.php`, eliminado el bloque generador del data: URI y borrado `manifest.json`. Verificado separadamente en navegador: `manifest.php` responde 200 con `application/manifest+json`, JSON válido y `start_url` resuelve contra la base real (`http://…/index.php`).
2. **`purpose: "any maskable"` combinado en los iconos** de `manifest.php`: contra la best practice de PWA (el icono se declararía usable a la vez con y sin zona segura; Chrome lo penaliza en el check de instalabilidad/Lighthouse). → Cada {192,512} dividido en dos entradas: `any` + `maskable` (4 iconos en total).
3. **`save_data.php` era un endpoint muerto con superficie de escritura:** ningún fichero lo invoca (solo el service worker lo listaba como no-cacheable y el informe histórico). → Eliminado el fichero y la referencia en `service-worker.js` (mismo caché v16, aún no desplegado, no hace falta bump). Se conserva `api.php` **no por limpieza sino por prudencia**: es un endpoint de consulta individual que no usa el frontend actual, pero puede consumirlo un script externo tuyo (mismo host + API key); si confirmas que nada externo lo llama, candidata a eliminación en la siguiente ronda.

### Ronda 7 — CSRF, sesión y cabeceras de seguridad
1. **La página de gestión era operable sin token CSRF.** Los dos POST de `gestionar_cartera.php` (login y guardado masivo de posiciones) carecían de token: cualquier página externa podía forjar la acción sin que el usuario lo viera. Mitigado hoy de facto por el `SameSite=Lax` implícito de algunos navegadores, pero PHP no lo declaraba (cookie sin atributos, sin `HttpOnly`, sin `Secure` en HTTPS) y la sesión nunca se regeneraba tras el login (fijación de sesión posible). → Solución: cookie de sesión con `httponly: true`, `samesite: Lax` y `secure` cuando hay HTTPS (detectado por `HTTPS` / `X-Forwarded-Proto`); `session_regenerate_id(true)` tras login exitoso; token CSRF por sesión (`random_bytes(32)`, comparado con `hash_equals`) inyectado como campo oculto en ambos formularios y validado antes de cualquier acción POST — un POST sin/manipulado token devuelve ahora " Sesión expirada" (login) o flash de error sin escribir nada (guardado).
2. **Sin cabeceras de seguridad en `.htaccess`:** nada frenaba framing externo (clickjacking) ni sniffing MIME. → Añadidas `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` y `Referrer-Policy: strict-origin-when-cross-origin` dentro del bloque `mod_headers` existente (parseable por el mismo `.htaccess` que ya usa `Header set`, mismo constraint de hosting).
3. **Precache del SW auditado (sin cambio):** los JS/CSS van network-first con fallback a caché en runtime (se auto-suelan en la primera visita), así que no precargarlos es defendible; las fuentes de Google precargan el CSS pero no los binarios woff2 (`fonts.gstatic.com`), que quedan cache-first en runtime. **Todo defendible — sin parche:** la capacidad offline total requeriría precargar los woff2, queda anotado como futura deuda menor, no parcheado para no inflar el precache con URLs dinámicas de `gstatic`.

### Ronda 8 — Accesibilidad y contraste (medido con WCAG)
1. **Chequeos estructurales limpios** (auditoría DOM en navegador): 1 solo `h1`, cero botones/enlaces sin nombre accesible, cero `img` sin `alt`, cero inputs sin label/placeholder/aria, foco nativo (outline del UA) intacto — no hay ningún `outline: none` en las hojas de estilo, así que el ring del teclado sobrevive, y el toggle de tema lleva `aria-label` + `title`. Los modales ya tienen `role="dialog"` + `aria-modal` + `aria-labelledby` y se cierran con `Escape`/clic fuera.
2. **Contraste real (cálculo WCAG 2.x en nodos visibles, componiendo fondos translúcidos con alpha sobre ancestros):**
    - Tema oscuro: subtítulo 7.45 ✓ (4.5 exigido).
    - Tema claro: `h1`/`.card-value`/banner 17.06 ✓, subtítulo 4.55 ✓, `text-danger` 4.62 ✓ (justo pero pasa), y **`text-success` 3.6 ✗ — fallo AA**: el verde `#059669` sobre tarjeta blanca no llegaba. → Cambiado `--success` solo en `[data-theme="light"]` a `#047857` (emerald-700), re-medido tras el cambio: **5.48 ✓**, y el tema oscuro conserva su verde original (`#10b981`) intacto.
    - Nota de método: la primera medición daba 1.18 para `h2`: artefacto del primer arnés, no componía el alpha al resolver el fondo; corregido midiendo el fondo efectivo composite. Los ratios citados son los del arnés corregido.

### Ronda 9 — Rendimiento: el peso oculto del HTML
1. **Medición en producción (Performance API + red, navegador real):** TTFB 153 ms, DOMContentLoaded 321 ms — razonable. Pero el documento HTML pesa **122 KB en cable (y ya con brotli `content-encoding: br`)** y **1.461 KB decodificados**, de los cuales **680 KB son JavaScript inline**: PHP serializaba floats con su ruido binario completo (`323988.73999999999068677425384521484375` — cada valor del historial con 12–17 decimales absurdos). El br disfraza los bytes, pero el navegador igual parsea 1,4 MB de DOM y megas de JSON en cada visita, y en móvil con caché fría es el principal coste. → Fix: helper recursivo `roundFloats($x, $dec = 4)` en `index.php`, aplicado a `PORTFOLIO_HISTORY`, `IBEX_HISTORY` y `cleanDbJson` (el JSON por-ticker del pie); y el ancho porcentual del `weight-bar` (`width:71.615128496521%` → `width:71.62%`) redondeado a 2 decimales.
2. **Verificación con BD de pruebas local (SQLite):** tras el fix, `grep` sobre el HTML generado → **0 floats con ≥5 decimales en todo el documento** (antes había miles de valores con 12–17 decimales en el JSON largo de prod); el bloque inline `SECTOR_DATA…</script>` pesa 55 KB en local (el 680 KB de producción venía sobre todo del historial largo; el ahorro escala con cada punto: de ~14–17 caracteres por float a ~6–9). Página carga en navegador local con **0 errores y 0 avisos de consola**.
3. **No parcheado (defendido):** no se quita el `Cache-Control: no-store` del HTML ni se muda el JSON inline a un endpoint — el panel necesita el dato al primer render y el `no-store` es una decisión explícita (datos de cartera frescos). Con el fix del ruido el coste queda dominado por datos reales, no por basura de serialización.

### Ronda 10 — Auditoría de recursos y limpieza final (los dos últimos puntos del listón)
1. **Todos los recursos referenciados cargan correctamente (criterio de listón):** auditoría sistemática con el servidor de pruebas — locales: `style.css`, `mejoras_v3.css`, `script.js`, `manifest.php`, `gestionar_cartera.php`, `favicon.ico`, `icon-192.png`, `icon-512.png` → **todas 200**; externas: `chart.js@4.6.1/dist/chart.umd.min.js`, `chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js` (los dos CDN del `<head>` y del precache SW), y la hoja de Google Fonts → **200**. Anchors: `index.php` no usa ningún `href="#…"` (los anclajes JS van por `getElementById`, todos con ids presentes en el DOM, comprobado en la ronda 8). El precache del SW no incluye nada que no exista.
2. **Carpeta sin código obsoleto (criterio de listón):** los ficheros muertos de la raíz — `index.backup.php`, `script.backup.js`, `style.backup.css`, `config.php.prod.bak`, `cache_data.json.prod.bak` — **no están rastreados por git** (el `.gitignore` los filtra), así que borrarlos sería perder historia sin red de seguridad: no se eliminan, se **archivan en `_legacy/`** con README, y la raíz queda limpia (verificado con `ls`). El deploy los excluye (patrones `**/*backup*` y `**/*.bak` de la ronda 5) y añade `**/_legacy/**` a la lista.3. **Checklist de listón final cerrado:** ✓ carpeta limpia y sin obsoletos; ✓ 0 errores de consola (verificado en ronda 9 local y en prod); ✓ responsiva (capturas desktop/móvil `_qa/`); ✓ todos los recursos 200 y preparados para desplegar sin incidencias (pipeline ya purga lo muerto).

### Ronda 11 — Datos reales de producción (BD de InfinityFree importada a SQLite)
1. **Contexto aportado:** la BD vive en InfinityFree y se refresca con cada chequeo (con el mercado cerrado no se actualiza); el usuario entrega el volcado real `if0_37663314_acciones_glm5.sql` (380 KB, phpMyAdmin, MariaDB 11.4, 28-10-2026). Para probar con datos reales se escribió `_qa/convert_dump_to_sqlite.py` (conversión CREATE TABLE + INSERT, saltando directivas MySQL: `SET/LOCK/ALTER/ENGINE`, y normalizando `current_timestamp()` → `CURRENT_TIMESTAMP`, que SQLite no admite con paréntesis) y se importó a `local_db_real.sqlite`: **8 valores con `history_json` real de hasta 49.321 caracteres**, `historial` y `portfolio_snapshots` vacías en el volcado.
2. **Hallazgo honesto sobre el fix de la ronda 9:** con la BD real, el doc WITH y WITHOUT `roundFloats` pesan lo mismo (323 vs 324 KB) y hay **0 floats largos en ambos** — porque MySQL entrega los `DECIMAL` como strings y PDO no los convierte a float. El ruido binario que medí en producción (680 KB inline) nace de los valores que **PHP calcula como float** (el `SECTOR_DATA` viejo de prod mostraba literalmente `323988.73999999999068677425384521484375`), y en esos applies el `roundFloats()` + los `round()` ya presentes funcionan: el HTML generado con la BD real tiene **0 floats con ≥5 decimales**. Cierre honesto: el fix era necesario para las ramas calculadas, no para los datos planos de la BD.
3. **Salto vertical del gráfico — pendiente de la ronda 3 — ahora cerrado con datos reales:** la serie real tiene **255 puntos (2025-08-18 → 2026-10-28)** y **0 saltos bruscos día a día** con umbral ±15%. Último punto 448.080,9 € (hoy) vs. KPI actual 453.234 € ≈ +1,15% (cierre del snapshot vs. valor en vivo) — coherente. Veredicto: el salto detectado en pruebas era un artefacto del SQLite de tests con datos sembrados, NO un bug de código.
4. **Higiene:** `config.local_real.php` (nueva config de pruebas con la BD real) añadida al `exclude` del deploy vía patrón `**/config.local_*.php` (además `**/*.sqlite` cubre ambas BD de pruebas); YAML validado. Del volcado se descubre que `portfolio_snapshots` está vacía en producción: la serie del portfolio la sirve `cache_data.json`, respetido como decisión de diseño.

### Ronda 13 — Bug real en producción tras el deploy: el anti-bot de InfinityFree rompía la descarga del manifiesto
1. **Detectado en vivo con el navegador tras el primer deploy** de las rondas anteriores: la página funcionaba pero Chrome lanzaba `Manifest: Line: 1, column: 1, Syntax error` apuntando a `manifest.php?v=4.0`. Un `fetch()` desde JS recibía el JSON correcto, así que el primer diagnóstico (el param `charset` del Content-Type) era un espejismo: corregido y desplegado, el error seguía. Capturando con CDP la petición real del manifiesto se vio la causa: **la petición que el parser de Chrome hace al manifiesto (tipo "Manifest") es respondida por el anti-bot del hosting con `Content-Type: text/html`** — Chrome recibe HTML donde espera JSON y la reporta como error de sintaxis. En local nunca ocurre porque `php -S` no tiene ese filtro.
2. **Solución: manifiesto estático** — `manifest.webmanifest` (mismo contenido, JSON validado, 4 iconos y `start_url` resolviendo contra la base real); el `<link rel="manifest">` en `index.php` lo apunta y `manifest.php` se elimina, sin duplicados (criterio de la ronda 6). Los recursos que no son `.php` no pasan por el anti-bot. En local, la página carga con **0 errores y 0 avisos** y el manifiesto responde con el MIME exacto.
3. **Lección registrada:** la verificación en producción tras cada deploy es parte del trabajo — un build local limpio no garantiza nada cuando hay un proxy/anti-bot de por medio.

## 3. Archivos modificados (entregables en `D:\Acciones_GLM5`)

- `lib_http.php` — nuevo helper de SSL (`ssl_strict()`, `aplicar_ssl($ch)`).
- `api.php` — auth same-origin real + SSL estricto + `require lib_http.php`.
- `save_data.php` — auth same-origin real + SQL CASE + SSL.
- `quick_update.php` — auth same-origin real (sin el bypass por Host), sin `ACAO *`, SQL CASE, SSL en los 4 cURLs.
- `cron_update.php` — SQL CASE, SSL en 4 cURLs.
- `ibex_update.php` — SSL estricto en 2 cURLs.
- `perplexity_report.php` — SSL estricto (endpoint IA).
- `style.css` — overrides de tema claro (header, título, banner IBEX, toast, fundido de tabla).
- `script.js` — helper `esc()` y escape de `${o.name}` en el innerHTML del diagnóstico.
- `index.php` — CDN de Chart.js y adapter fijados a versión exacta.
- `service-worker.js` — URLs fijadas en el precache + bump del caché (v15→v16).
- `gestionar_cartera.php` — `GESTION_PASSWORD` admite hash bcrypt/argon (o texto plano existente, comparación con hash_equals).
- `config.php` — 503 en fallo de BD; restaurado con las credenciales reales de producción.
- `gestionar_cartera.php` — además de lo anterior: sesión con `httponly/SameSite/Secure`, `session_regenerate_id` post-login y token CSRF en ambos formularios (ronda 7).
- `.htaccess` — además de CORS y bloque de ficheros: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` (ronda 7).
- `.gitignore` — ignores de `_qa/`, `.playwright-mcp/`, `local_test.sqlite`, `config.local_test.php`.
- `.github/workflows/deploy.yml` — exclusión de artefactos de test/backups/documentación interna del FTP de producción (rondas 5 y 10, con `**/_legacy/**`).
- `_legacy/` — archivado de los cinco ficheros obsoletos de la raíz con README (ronda 10; no se despliega ni se rastrea en git).
- `_qa/convert_dump_to_sqlite.py`, `local_db_real.sqlite`, `config.local_real.php` — infraestructura de pruebas con la BD real de producción (ronda 11; todos excluidos del deploy y del git).
- `style.css` — además de los overrides del tema claro: `--success` en claro → `#047857` por contraste AA (ronda 8).
- `index.php` — además de lo anterior: helper `roundFloats()` en los JSON inline (historiales + JSON por-ticker) y `weight-bar` con 2 decimales, por el peso del documento (ronda 9).
- `manifest.php` — queda como manifiesto canónico (iconos `any`/`maskable` separados); `manifest.php?v=4.0` es lo que enlaza `index.php`.
- `manifest.json`, `save_data.php` — **eliminados** (duplicado huérfano y endpoint muerto, ronda 6).
- `INFORME_TECNICO.md` — este informe.

## 4. Verificaciones realizadas

- **Estáticos y sintaxis:** `php -l` en los 10 ficheros PHP → sin errores; `node --check script.js` y `service-worker.js` → OK.
- **CDN fijados:** `chart.js@4.6.1/dist/chart.umd.min.js` → 200; `chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js` → 200. Página local cargada en navegador: `Chart.version === "4.6.1"`, adapter presente, gráfico de evolución del portfolio dibujado con cero errores de consola (captura `_qa/qa-chart-pinned.png`).
- **Helper `esc()`:** test en página confirma `<img onerror=…>` → `&lt;img …&gt;` sin reintroducir etiquetas.
- **Login de gestión:** función de validación probada en las 4 combinaciones (plano correcto→true, plano incorrecto→false, hash correcto→true, hash incorrecto→false).
- **Endpoint auth (antes vs. después):**
  - `api.php` sin cabeceras → 403 ✓ (antes 200).
  - `api.php` con `Sec-Fetch-Site: none` (bypass antiguo) → 403 ✓.
  - `api.php` con `Referer: https://evil.com/…+tu dominio dentro` → 403 ✓.
  - `api.php` con Referer legítimo (mismo host) → 200 ✓ (cotización real de Yahoo, IBE.MC 22.03 €).
  - `quick_update.php` sin auth → 403 ✓ (antes pasaba por el bypass del Host).
- **Flujo completo:** `quick_update.php` con caché válida servido; operación batch Yahoo (spark) ejecutada con SSL estricto y refresco DB en pruebas locales previas (con el config de test SQLite, cuando la BD era modificable).
- **Tema claro:** capturas en `_qa/qa-light-header2.png` con el título y header correctos.
- **Conectores de BD:** index/api responden 503 con el config de producción cargado localmente (la BD remota de InfinityFree no permite acceso externo — comportamiento esperado en local, correcto en prod).
- **Producción chequeada en navegador (ronda 5):** `https://txopo13.lovestoblog.com/Acciones_GML5/` → 200, 0 errores y 0 avisos de consola, SW `v=15` activado, `Chart.version === "4.6.1"`. Anti-regresión: nada rompe hoy; los cambios de esta sesión entran al desplegar.
- **deploy.yml:** YAML válido (`yaml.safe_load`) tras añadir las exclusiones.
- **Manifiesto (ronda 6):** `manifest.php` → 200 con `application/manifest+json`, JSON parseable, 4 iconos con `purpose` correcto, `start_url` resuelto contra la base real. Página de pruebas con 0 errores y 0 avisos de consola.
- **Referencias muertas:** `grep` del proyecto → 0 menciones a `save_data.php` en código; eliminados `manifest.json` y el bloque data-URI de `index.php` sin restos.
- **Config de producción:** restaurada y verificada (`grep infinityfree` OK) tras el test con SQLite.
- **CSRF + sesión (ronda 7, con config SQLite de test y arnés curl):**
  - Login con contraseña y token correctos → **302** (redirect a gestión) ✓.
  - Login sin token → **rechazado** (re-renderiza el formulario de acceso, error " Sesión expirada") ✓.
  - Guardado con token de sesión válido → flash "Cambios guardados correctamente" ✓ (UPDATE ejecutado en la BD de pruebas).
  - Guardado con token manipulado (`csrf_token=poison`) → flash "Sesión expirada: cambios no guardados" y **ninguna escritura** ✓.
  - Cookie de sesión en el jar marcada `HttpOnly` ✓; sesión regenerada tras login (jar contiene el nuevo ID, 1 sesión activa) ✓.
- **Static:** `php -l` limpio en los 9 ficheros PHP del proyecto tras la ronda 7; `node --check service-worker.js` OK; `config.php` re-verificado como restaurado a producción.
- **Contraste re-medido tras el fix (ronda 8):** `h2.text-success` en tema claro → **5.48** (antes 3.6) sobre blanco; tema oscuro sin cambios (`rgb(16,185,129)`, ratio alto). Auditoría DOM limpia en los otros vecheros (nombres accesibles/alt/labels/foco).
- **Config de producción re-restaurada tras ronda 8** (`grep infinityfree` OK).
- **Auditoría de recursos (ronda 10):** 7 URLs locales + 3 externas + `icon-512.png`/`favicon.ico` → **todas 200**; 0 anchors rotos; raíz sin `*.backup.*`/`*.bak`; `deploy.yml` YAML válido con `_legacy/` excluido; `php -l` sin FAIL en los 9 PHP; `config.php` re-restaurado a producción.
- **BD real importada (ronda 11):** 11 statements SQL ejecutados, 8 filas de `valores` con historiales reales (máx. 49.321 chars); página cargada con la BD real: serie de 255 puntos, 0 saltos bruscos, 0 floats largos en el HTML, consola limpia; `config.php` re-restaurado a producción y `deploy.yml` YAML válido tras excluir `**/config.local_*.php` y `**/*.sqlite`.
- **Ronda 13 (en vivo):** CDP confirmó que la petición "Manifest" recibía `text/html` del anti-bot; con el manifiesto estático, la página local carga con 0 errores y el `.webmanifest` responde `application/manifest+json` con 4 iconos y `start_url` resuelto. Verificación final pendiente en producción tras el push (se ejecuta al cerrar la sesión).
- **Ronda 12:** `handleAnalysis()` auditada (0 llamadas de red); hash bcrypt validado con la función real de decisión (`password_verify` contra la clave actual → true, clave errónea → false); repo empujado a `main`.
- **Rendimiento (ronda 9):** producción medida (TTFB 153 ms / DCL 321 ms / doc 122 KB en cabo con brotli / 1.461 KB decodificado / inline JS 680 KB). Tras `roundFloats`: 0 floats con ≥5 decimales en el HTML de pruebas, inline script 55 KB (BD corta), consola limpia. `php -l` OK y `config.php` re-restaurado a producción tras el test.

## 5. Decisiones por ambigüedad (sin preguntar)

1. **Helper centralizado vs. editar cada cURL:** se eligió `lib_http.php` (sonda una sola vez + degradación segura) porque tocar 12 sitios con la lógica inline generaría más borde del que se arregla (lo que de hecho la ronda 2 del árbitro revienta).
2. **El salto del gráfico no se "arregla" con lógica:** se decidió no parchear series desde el cliente; el dato es incoherente en el entorno de pruebas y coherente en producción.
3. **HTTPS en helper automático:** estricto por defecto; la degradación automática es deliberada para no tumbar la app en free hosting sin CA bundle, dejendo constancia en error_log con sonda cada 24 h.
4. **CORS:** el `.htaccess` mantiene CORS restringido; el `ACAO *` en `manifest.php` se respeta porque los manifiestos PWA necesitan acceso público.

## 6. Pendientes conocidos (no son incidencias del código)

- **Deuda anotada (ronda 7):** precargar los woff2 de `fonts.gstatic.com` en el SW si se quiere la fuente exacta también en la primera carga sin conexión; no parcheado para no inflar el precache con URLs generadas por Google.
- **Nota ronda 7:** las cabeceras nuevas viven dentro de `<IfModule mod_headers.c>`; en InfinityFree `mod_headers` ya está activo (el CORS del `.htaccess` lo usa y funciona en producción).
- **Push/FTP para desplegar rondas 5 y 6:** el SW en producción sigue en `v=15`; tras el push a `main`, el workflow debe subir `index.php`/`service-worker.js`/`manifest.php` con el caché v16 y las exclusiones nuevas. OJO: `FTP-Deploy-Action` sincroniza por defecto y **borra del servidor** los ficheros que ya no existen localmente, así que el próximo deploy purgará `save_data.php` y `manifest.json` de producción automáticamente (el endpoint está protegido igualmente en el mientras tanto).
- **`api.php` (candidato a eliminación):** **resuelto en la ronda 12:** el frontend no lo usa (el botón «Análisis técnico» solo lee los `data-*` de la fila), pero se conserva blindado como endpoint de consulta individual por si algo externo tuyo lo consume.- Verificar en producción tras desplegar: la primera petición crea `private_cache/ssl_strict.flag`; si el error_log registra `[lib_http] Verificación SSL desactivada…`, avísame para pre-cachear el CA bundle del hosting.
- `eror.txt` histórico refleja 404s antiguos de `quick_update.php` en producción; tras subir esta versión, la próxima ejecución del cron de GitHub Actions debería limpiarlo.
- GESTION_PASSWORD: **pendiente de tu mano, con las instrucciones exactas**: en el gestor de ficheros de InfinityFree (htdocs/Acciones_GML5/config.php) sustituye la línea `define('GESTION_PASSWORD', …)` por la versión con el hash ya generado (indicado al usuario en el chat de la sesión; no se escribe aquí por seguridad). El código ya soporta ambos formatos, así que la página no se bloquea antes ni después del cambio.
