<?php
// lib_http.php — Verificación SSL compartida para las llamadas cURL del proyecto.
//
// El proyecto consume varias APIs externas (Yahoo Finance, Groq, Perplexity).
// Por defecto la verificación de certificados va ACTIVADA. Si el hosting no
// dispone de un bundle de CA (típico en free hosting), la sonda de abajo lo
// detecta una sola vez por proceso y, sin esta comprobación, cualquier llamada
// saliente fallaría; en ese caso se degrada a modo permisivo dejando constancia
// en error_log(). Nunca se degrada por un error de red puntual: solo por errores
// de importación/validación del certificado.

function ssl_strict(): bool
{
    static $strict = null;

    if ($strict === null) {
        // Cachear la decisión en disco 24 h: la sonda cuesta una conexión HTTPS
        // y con hosting CGI se pagaría en cada petición.
        $flagFile = __DIR__ . '/private_cache/ssl_strict.flag';
        if (is_file($flagFile) && time() - filemtime($flagFile) < 86400) {
            $raw = @file_get_contents($flagFile);
            if ($raw === '1' || $raw === '0') {
                return $strict = ($raw === '1');
            }
        }

        $ch = curl_init('https://finance.yahoo.com/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Errores que indican cadena de certificados / CA inservible en este host.
        // Numeros literales por portabilidad entre builds de la extensión cURL.
        $caErrors = [35, 51, 53, 58, 59, 60, 77, 90, 91];

        if (in_array($errno, $caErrors, true)) {
            $strict = false;
            error_log('[lib_http] Verificación SSL desactivada: el host no dispone de un CA bundle válido (errno ' . $errno . ').');
        } else {
            $strict = true;
        }

        if (!is_dir(__DIR__ . '/private_cache')) {
            @mkdir(__DIR__ . '/private_cache', 0755, true);
        }
        @file_put_contents($flagFile, $strict ? '1' : '0');
    }

    return $strict;
}

// Aplica la política SSL de arriba sobre un manejador cURL ya existente.
// Debe llamarse después del curl_setopt_array() principal de cada llamada.
function aplicar_ssl($ch): void
{
    $strict = ssl_strict();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $strict);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $strict ? 2 : 0);
}
