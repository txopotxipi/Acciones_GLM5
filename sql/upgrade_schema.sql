-- upgrade_schema.sql — Schema additions for CarteraPro v2
-- Add sector column to valores if it doesn't exist
ALTER TABLE `valores`
ADD COLUMN IF NOT EXISTS `sector` VARCHAR(50) DEFAULT NULL
AFTER `nombre`;
-- Update sectors for existing tickers
UPDATE `valores`
SET `sector` = 'Energía'
WHERE `ticker` IN ('IBE.MC', 'ELE.MC');
UPDATE `valores`
SET `sector` = 'Telecomunicaciones'
WHERE `ticker` = 'TEF.MC';
UPDATE `valores`
SET `sector` = 'Banca'
WHERE `ticker` = 'BBVA.MC';
UPDATE `valores`
SET `sector` = 'Petróleo'
WHERE `ticker` = 'REP.MC';
UPDATE `valores`
SET `sector` = 'Media'
WHERE `ticker` IN ('A3M.MC', 'MFE-A');
-- Ensure ticker_yahoo column exists (for tickers with different Yahoo symbols)
ALTER TABLE `valores`
ADD COLUMN IF NOT EXISTS `ticker_yahoo` VARCHAR(20) DEFAULT NULL
AFTER `ticker`;
-- Map Yahoo symbols
UPDATE `valores`
SET `ticker_yahoo` = 'MFEA.MI'
WHERE `ticker` = 'MFE-A';