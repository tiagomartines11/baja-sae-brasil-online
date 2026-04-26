-- phpBB Baja user (full access to its own DB only)
CREATE USER IF NOT EXISTS 'phpbb_baja'@'%' IDENTIFIED BY 'devphpbbbaja';
GRANT ALL PRIVILEGES ON phpbb_baja.* TO 'phpbb_baja'@'%';

-- phpBB Formula user (full access to its own DB only)
CREATE USER IF NOT EXISTS 'phpbb_formula'@'%' IDENTIFIED BY 'devphpbbformula';
GRANT ALL PRIVILEGES ON phpbb_formula.* TO 'phpbb_formula'@'%';

-- Baja resultados app user (matches prod username 'resultados')
CREATE USER IF NOT EXISTS 'resultados'@'%' IDENTIFIED BY 'devresultados';
GRANT ALL PRIVILEGES ON baja_resultados.* TO 'resultados'@'%';
GRANT SELECT ON phpbb_baja.* TO 'resultados'@'%';

FLUSH PRIVILEGES;
