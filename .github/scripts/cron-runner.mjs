import { chromium } from 'playwright';

// Ejecuta cron_update.php desde el propio dominio.
// InfinityFree protege el hosting con un desafío JavaScript (aes.js), así que
// curl/requests externos reciben HTML en vez de JSON. Un navegador real resuelve
// el desafío y la petición same-origin pasa la validación de cron_update.php.

const baseUrl = process.env.SITE_URL || 'https://txopo13.lovestoblog.com/Acciones_GML5/';
const cronUrl = new URL('cron_update.php', baseUrl).toString();
const apiKey = process.env.CRON_API_KEY || '';

function log(message) {
  console.log(`[cron] ${message}`);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'es-ES',
  timezoneId: 'Europe/Madrid',
});
const page = await context.newPage();

try {
  log(`Abriendo ${baseUrl}`);
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Esperar a que el desafío de InfinityFree redirija a la aplicación real.
  let appReady = false;
  for (let i = 0; i < 20; i++) {
    const state = await page
      .evaluate(() => ({
        title: document.title || '',
        app: Boolean(document.querySelector('meta[name="mobile-web-app-capable"]')),
      }))
      .catch(() => null);

    if (state?.app || state?.title.includes('Cartera')) {
      appReady = true;
      break;
    }
    await page.waitForTimeout(1000);
  }
  log(appReady ? 'Aplicación cargada; ejecutando cron' : 'Aplicación no confirmada; se intenta el cron igualmente');

  let payload = null;
  let lastStatus = 0;

  for (let attempt = 1; attempt <= 6 && !payload; attempt++) {
    try {
      const response = await page.evaluate(async ({ url, key }) => {
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (key) headers['X-Api-Key'] = key;

        const res = await fetch(url, { cache: 'no-store', headers });
        return { status: res.status, text: await res.text() };
      }, { url: cronUrl, key: apiKey });

      lastStatus = response.status;
      try {
        payload = JSON.parse(response.text);
      } catch {
        payload = null;
      }

      if (!payload) {
        log(`Intento ${attempt}: HTTP ${response.status}, respuesta no JSON`);
      }
    } catch (error) {
      log(`Intento ${attempt}: ${error.message}`);
    }

    if (!payload) await page.waitForTimeout(5000);
  }

  if (!payload) {
    throw new Error(`No se pudo obtener JSON de cron_update.php (último HTTP ${lastStatus})`);
  }

  console.log(JSON.stringify({
    success: payload.success,
    updated: payload.updated,
    errors: payload.errors,
    timestamp: payload.timestamp,
    results: payload.results,
  }, null, 2));

  if (payload.success !== true || Number(payload.errors) > 0) {
    throw new Error(`El cron terminó con errores (${payload.errors ?? 'desconocido'})`);
  }

  log('Actualización completada');
} finally {
  await context.close();
  await browser.close();
}
