<?php
/**
 * Build maps for Icecat thumbnails: catalogue SKU keys + normalized product names.
 * Lenovo Local LPN does not match sales_out SKU for many headsets — matching by EPOS product_name fixes that.
 */

function sales_out_product_thumb_manifest(PDO $pdo): array
{
    $bySku = [];
    $byProductName = [];

    $normProductName = static function (?string $s): string {
        $s = trim((string) $s);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }

        return strtolower($s);
    };

    $cols = $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_thumb'")->fetch();
    if (!$cols) {
        return ['sku' => [], 'byProductName' => []];
    }

    $stmt = $pdo->query("
        SELECT TRIM(REPLACE(sku, ' ', '')) AS sku_norm,
               TRIM(COALESCE(product_name, '')) AS product_name,
               image_thumb
        FROM sales_out_products
        WHERE image_thumb IS NOT NULL
          AND TRIM(image_thumb) <> ''
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $skuNorm = $row['sku_norm'] ?? '';
        $thumb = trim((string) ($row['image_thumb'] ?? ''));
        $pkey = $normProductName($row['product_name'] ?? '');
        if ($thumb === '' || strncasecmp($thumb, 'http', 4) !== 0) {
            continue;
        }
        if ($skuNorm !== '' && !isset($bySku[$skuNorm])) {
            $bySku[$skuNorm] = $thumb;
        }
        if ($pkey !== '' && !isset($byProductName[$pkey])) {
            $byProductName[$pkey] = $thumb;
        }
    }

    return ['sku' => $bySku, 'byProductName' => $byProductName];
}
