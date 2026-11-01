<?php

require __DIR__ . '/db.php';

function keyword_for(string $name, string $category): string
{
    $map = [
        'cement'        => 'cement bag',
        'sand'          => 'sand pile',
        'gravel'        => 'gravel',
        'hollow blocks' => 'concrete blocks',
        'deformed bar'  => 'rebar steel construction',
        'nails'         => 'nails hardware',
        'hex bolt'      => 'bolts nuts hardware',
        'screw'         => 'screws hardware',
        'staple'        => 'stapler',
        'paint roller'  => 'paint roller',
        'paint brush'   => 'paint brush',
        'enamel paint'  => 'paint cans red',
        'latex paint'   => 'paint cans',
        'paint'         => 'paint cans',
        'thinner'       => 'paint cans',
        'pvc elbow'     => 'pvc pipe fittings',
        'pvc pipe'      => 'pvc pipes',
        'solvent'       => 'construction materials',
        'water closet'  => 'toilet',
        'faucet'        => 'faucet',
        'led bulb'      => 'led light bulb',
        'extension cord' => 'extension cord',
        'circuit breaker' => 'circuit breaker',
        'electrical wire' => 'electrical wire copper',
        'conduit'       => 'conduit pipes',
        'claw hammer'   => 'claw hammer',
        'screwdriver'   => 'screwdriver set',
        'wrench'        => 'adjustable wrench',
        'hacksaw'       => 'hacksaw',
        'measuring tape' => 'tape measure',
        'helmet'        => 'hard hat safety',
        'gloves'        => 'work gloves',
        'goggles'       => 'safety goggles',
    ];
    $lower = mb_strtolower($name);
    foreach ($map as $needle => $kw) {
        if (mb_strpos($lower, $needle) !== false) return $kw;
    }
    
    return mb_strtolower($category) . ' tool hardware';
}

$ctx = stream_context_create(['http' => ['header' => "User-Agent: MarinasPOS/1.0 (school project)\r\n", 'timeout' => 25]]);

$rows = db()->query(
    "SELECT p.id, p.name, p.image, c.name AS category
       FROM products p JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active' AND (p.image IS NULL OR p.image = '')
      ORDER BY p.id"
)->fetchAll();

$dir = __DIR__ . '/Assets/uploads';
if (!is_dir($dir)) mkdir($dir, 0775, true);

$done = 0; $failed = [];
foreach ($rows as $p) {
    $kw    = keyword_for($p['name'], $p['category']);
    $api   = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrsearch='
           . rawurlencode($kw) . '&gsrnamespace=6&gsrlimit=5&prop=imageinfo&iiprop=url|mime&iiurlwidth=320&format=json';
    $thumb = null;
    try {
        $data = json_decode(@file_get_contents($api, false, $ctx), true);
        $pages = $data['query']['pages'] ?? [];
        foreach ($pages as $page) {
            $ii = $page['imageinfo'][0] ?? null;
            if (!$ii) continue;
            if (in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                $thumb = $ii['thumburl'];
                break;
            }
        }
    } catch (Throwable $e) {  }

    if (!$thumb) { $failed[] = $p['name'] . ' (' . $kw . ')'; continue; }

    $bin = @file_get_contents($thumb, false, $ctx);
    if ($bin === false || strlen($bin) < 3000) { $failed[] = $p['name'] . ' (download)'; continue; }

    $name = 'prod_' . $p['id'] . '_' . date('His') . '.jpg';
    file_put_contents($dir . '/' . $name, $bin);
    $st = db()->prepare('UPDATE products SET image = ? WHERE id = ?');
    $st->execute(['Assets/uploads/' . $name, $p['id']]);
    $done++;
    echo "✓ #{$p['id']} {$p['name']} <- {$kw}\n";
    usleep(400000); 
}

echo "\nDONE: {$done} images | failed: " . count($failed) . "\n";
foreach ($failed as $f) echo "  ✗ {$f}\n";
