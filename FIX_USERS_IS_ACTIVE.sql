-- Script temporal para agregar el campo is_active a la tabla users
-- Ejecuta este script manualmente en tu base de datos

-- 1. Agregar la columna is_active si no existe
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE AFTER avatar;

-- 2. Actualizar todos los usuarios existentes a activos
UPDATE users SET is_active = TRUE WHERE is_active IS NULL;

-- Verificar los cambios
SELECT id, name, email, is_active FROM users;
