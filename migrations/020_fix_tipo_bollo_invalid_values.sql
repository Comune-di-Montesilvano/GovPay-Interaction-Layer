-- Fix tipo_bollo values stored as labels instead of GovPay API codes.
-- Only '01' is a valid tipoBollo code per pagoPA spec; anything else is invalid.
UPDATE entrate_tipologie
SET tipo_bollo = NULL
WHERE tipo_bollo IS NOT NULL
  AND tipo_bollo NOT IN ('01');
