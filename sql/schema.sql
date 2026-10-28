-- ============================================================
-- Cartera Pro GLM5 — Esquema canónico de base de datos
-- ============================================================
-- Verificado contra la base de datos de producción el 2026-10-28.
-- Motor: InnoDB · Collation: utf8mb4_general_ci (salvo stock_historical_data).
--
-- Este archivo sustituye al antiguo dump local y a upgrade_schema.sql.
-- No contiene datos privados de la cartera: las posiciones se introducen
-- desde gestionar_cartera.php o con INSERTs propios.
--
-- Tablas incluidas:
--   · valores                 → posiciones, precios y datos fundamentales/técnicos
--   · portfolio_snapshots     → valor diario de la cartera
--   · ibex35_price_history    → histórico del IBEX 35 (fallback de index.php)
--   · stock_historical_data   → histórico por símbolo (fallback de index.php)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- valores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `valores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticker` varchar(20) NOT NULL,
  `ticker_yahoo` varchar(20) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `num_acciones` decimal(12,4) DEFAULT 0.0000,
  `precio_actual` decimal(12,4) DEFAULT 0.0000,
  `precio_cierre_anterior` decimal(12,4) DEFAULT 0.0000,
  `precio_cierre_2025` decimal(12,4) DEFAULT 0.0000,
  `precio_medio_compra` decimal(12,4) DEFAULT NULL,
  `fecha_compra` date DEFAULT NULL,
  `sector` varchar(60) DEFAULT NULL,
  `sector_color` varchar(9) DEFAULT NULL,
  `history_json` longtext DEFAULT NULL,
  `fundamentals_json` longtext DEFAULT NULL,
  `target_mean_price` decimal(12,4) DEFAULT NULL,
  `recommendation_key` varchar(30) DEFAULT NULL,
  `recommendation_mean` decimal(4,2) DEFAULT NULL,
  `dividend_yield` decimal(8,4) DEFAULT NULL,
  `dividend_rate` decimal(8,4) DEFAULT NULL,
  `payout_ratio` decimal(8,4) DEFAULT NULL,
  `trailing_pe` decimal(8,2) DEFAULT NULL,
  `forward_pe` decimal(8,2) DEFAULT NULL,
  `fifty_two_week_low` decimal(12,4) DEFAULT NULL,
  `fifty_two_week_high` decimal(12,4) DEFAULT NULL,
  `rsi_14` decimal(6,2) DEFAULT NULL,
  `sma_20` decimal(12,4) DEFAULT NULL,
  `sma_50` decimal(12,4) DEFAULT NULL,
  `tech_score` int(11) DEFAULT 0,
  `tech_signal` varchar(20) DEFAULT 'MANTENER',
  `volatilidad` decimal(6,2) DEFAULT NULL,
  `ultima_actualizacion` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticker` (`ticker`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- portfolio_snapshots
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_snapshots` (
  `fecha` date NOT NULL,
  `valor_total` decimal(12,2) NOT NULL,
  `num_posiciones` int(11) DEFAULT 0,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- ibex35_price_history (fallback de index.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ibex35_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(10) NOT NULL,
  `price` decimal(10,4) NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_symbol_date` (`symbol`,`date`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- stock_historical_data (fallback de index.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_historical_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(10) NOT NULL,
  `date` date NOT NULL,
  `open_price` decimal(10,4) NOT NULL,
  `high_price` decimal(10,4) NOT NULL,
  `low_price` decimal(10,4) NOT NULL,
  `close_price` decimal(10,4) NOT NULL,
  `adjusted_close` decimal(10,4) NOT NULL,
  `volume` bigint(20) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_symbol_date` (`symbol`,`date`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_close_price` (`close_price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Instrumentos de la cartera (sin posiciones)
-- ------------------------------------------------------------
-- Se insertan con 0 acciones para que gestionar_cartera.php pueda
-- editarlos en una instalación nueva. INSERT IGNORE no modifica las
-- filas que ya existan en producción.
INSERT IGNORE INTO `valores`
  (`ticker`, `ticker_yahoo`, `nombre`, `num_acciones`, `precio_medio_compra`, `sector`, `sector_color`)
VALUES
  ('IBE.MC', 'IBE.MC',  'Iberdrola',     0.0000, NULL, 'Energía',           '#10b981'),
  ('ELE.MC', 'ELE.MC',  'Endesa',        0.0000, NULL, 'Energía',           '#10b981'),
  ('TEF.MC', 'TEF.MC',  'Telefónica',    0.0000, NULL, 'Telecomunicaciones', '#3b82f6'),
  ('BBVA.MC','BBVA.MC', 'BBVA',          0.0000, NULL, 'Banca',             '#f59e0b'),
  ('REP.MC', 'REP.MC',  'Repsol',        0.0000, NULL, 'Petróleo',          '#ef4444'),
  ('MFE-A',  'MFEA.MI', 'MediaForEurope',0.0000, NULL, 'Media',             '#8b5cf6'),
  ('A3M',    'A3M.MC',  'Atresmedia',    0.0000, NULL, 'Media',             '#8b5cf6'),
  ('^IBEX',  '^IBEX',   'IBEX 35',       0.0000, NULL, 'Otro',              '#64748b');

SET FOREIGN_KEY_CHECKS = 1;
