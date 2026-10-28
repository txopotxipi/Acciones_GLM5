<?php
// manifest.php — Servidor dinámico de manifest PWA para hosting compatible
header('Content-Type: application/manifest+json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
?>
{
    "name": "Cartera Pro GLM5",
    "short_name": "Cartera Pro",
    "description": "Panel de control y análisis cuantitativo del portfolio GLM5",
    "start_url": "index.php",
    "scope": "./",
    "display": "standalone",
    "background_color": "#0b0f19",
    "theme_color": "#6366f1",
    "orientation": "portrait-primary",
    "icons": [
        {
            "src": "icon-192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any maskable"
        },
        {
            "src": "icon-512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any maskable"
        }
    ]
}
