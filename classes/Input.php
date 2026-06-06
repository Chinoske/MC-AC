<?php
/**
 * Input — Acceso seguro a datos de formularios POST/GET
 */
class Input
{
    /** ¿Existe algún dato del tipo indicado? */
    public static function exists(string $type = 'post'): bool
    {
        return match ($type) {
            'post'  => !empty($_POST),
            'get'   => !empty($_GET),
            default => false,
        };
    }

    /** Obtiene y sanea un campo POST/GET */
    public static function get(string $item, string $type = 'post'): string
    {
        $raw = match ($type) {
            'post'  => $_POST[$item]  ?? '',
            'get'   => $_GET[$item]   ?? '',
            default => '',
        };
        return self::sanitize((string) $raw);
    }

    /** Devuelve el valor raw de POST sin sanear (para dumps de texto) */
    public static function getRaw(string $item): string
    {
        return (string) ($_POST[$item] ?? '');
    }

    /** Sanea: quita HTML y recorta espacios */
    public static function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
