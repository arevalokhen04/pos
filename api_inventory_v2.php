<?php

require __DIR__ . '/db.php';

$user  = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input  = $method === 'POST' ? json_input() : [];

if ($method === 'GET') {
    switch ($action) {

        case 'list':
            $search = trim($_GET['search'] ?? '');
            $cat    = (int)($_GET['category'] ?? 0);
            $low    = !empty($_GET['low_stock']);
            $sortMap = [
                'name_asc'   => 'p.name ASC',
                'stock_asc'  => 'p.stock_qty ASC',
                'stock_desc' => 'p.stock_qty DESC',
                'price_asc'  => 'p.selling_price ASC',
                'price_desc' => 'p.selling_price DESC',
            ];
            $order = $sortMap[$_GET['sort'] ?? ''] ?? 'p.name ASC';
            $sql =
                'SELECT p.*, c.name AS category_name,
                        (p.display_qty <= p.reorder_level) AS is_low
                   FROM products p
                   JOIN categories c ON c.id = p.category_id
                  WHERE p.status = "active"';
            $params = [];
            if ($search !== '') {
                
                $sql .= ' AND (';
                $parts = [];
                foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    $parts[] = '(p.name LIKE ? OR p.barcode LIKE ?)';
                    $params[] = "%$w%";
                    $params[] = "%$w%";
                }
                $sql .= implode(' AND ', $parts) . ')';
            }
            if ($cat > 0) {
                $sql .= ' AND p.category_id = ?';
                $params[] = $cat;
            }
            if ($low) {
                $sql .= ' AND p.display_qty <= p.reorder_level';
            }
            $sql .= ' ORDER BY ' . $order;
            $st = db()->prepare($sql);
            $st->execute($params);
            json_out(['success' => true, 'products' => $st->fetchAll()]);

        case 'categories':
            $st = db()->query(
                'SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = "active") AS product_count
                   FROM categories c ORDER BY c.name'
            );
            json_out(['success' => true, 'categories' => $st->fetchAll()]);

        case 'low_stock':
            $st = db()->query(
                'SELECT p.id, p.name, p.stock_qty, p.reorder_level, p.unit, c.name AS category_name
                   FROM products p JOIN categories c ON c.id = p.category_id
                  WHERE p.display_qty <= p.reorder_level AND p.status = "active"
                  ORDER BY (p.stock_qty - p.reorder_level), p.name'
            );
            json_out(['success' => true, 'products' => $st->fetchAll()]);

        case 'movement':
            
            require_role('admin', 'super_admin');
            $days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)($_GET['days'] ?? 30) : 30;

            $st = db()->prepare(
                'SELECT p.id, p.name, c.name AS category_name, p.stock_qty, p.display_qty, p.cost_price, p.unit,
                        COALESCE(m.qty, 0)    AS sold30,
                        COALESCE(m.revenue, 0) AS revenue30,
                        m.last_sold
                   FROM products p
                   JOIN categories c ON c.id = p.category_id
                   LEFT JOIN (
                        SELECT si.product_id, SUM(si.qty) AS qty, SUM(si.subtotal) AS revenue, MAX(s.created_at) AS last_sold
                          FROM sale_items si JOIN sales s ON s.id = si.sale_id
                         WHERE s.created_at >= NOW() - INTERVAL ' . $days . ' DAY
                         GROUP BY si.product_id
                   ) m ON m.product_id = p.id
                  WHERE p.status = "active"
                  ORDER BY sold30 DESC, p.name'
            );
            $st->execute();
            $rows = $st->fetchAll();

            
            $movers = array_filter($rows, fn($r) => (int)$r['sold30'] > 0);
            $avg = $movers ? array_sum(array_column($movers, 'sold30')) / count($movers) : 0;
            /* fixed classification rule (user-defined): units sold ABOVE 10 in the
               selected period = fast; 1-10 = slow; 0 = no sales */
            $fastline = 10;

            $summary = ['fast' => 0, 'slow' => 0, 'dead' => 0, 'deadStockValue' => 0.0];
            foreach ($rows as &$r) {
                $r['sold30']      = (int)$r['sold30'];
                $r['revenue30']   = round((float)$r['revenue30'], 2);
                $r['total_stock']    = (int)$r['stock_qty'] + (int)$r['display_qty'];
                $r['stock_value'] = round(((float)$r['total_stock']) * ((float)$r['cost_price']), 2);
                $r['days_since']  = $r['last_sold'] ? (int)floor((time() - strtotime($r['last_sold'])) / 86400) : null;
                $r['movement']    = $r['sold30'] > $fastline ? 'fast' : ($r['sold30'] > 0 ? 'slow' : 'dead');
                $summary[$r['movement']]++;
                if ($r['movement'] === 'dead') $summary['deadStockValue'] += $r['stock_value'];
            }
            unset($r);
            $summary['deadStockValue'] = round($summary['deadStockValue'], 2);

            json_out(['success' => true, 'days' => $days, 'avg30' => round($avg, 1), 'summary' => $summary, 'stats' => $rows]);

        case 'movements':
            require_role('admin', 'super_admin');
            $st = db()->query(
                'SELECT m.*, p.name AS product_name, u.full_name AS user_name
                   FROM inventory_movements m
                   JOIN products p ON p.id = m.product_id
                   LEFT JOIN users u ON u.id = m.user_id
                  ORDER BY m.id DESC LIMIT 200'
            );
            json_out(['success' => true, 'movements' => $st->fetchAll()]);

        default:
            json_out(['success' => false, 'message' => 'Unknown action'], 400);
    }
}

switch ($action) {

    case 'save_product':
        
        require_role('super_admin');
        $id       = (int)($input['id'] ?? 0);
        $barcode  = trim($input['barcode'] ?? '');
        $name     = trim($input['name'] ?? '');
        $catId    = (int)($input['category_id'] ?? 0);
        $unit     = trim($input['unit'] ?? 'pc');
        $cost     = max(0.0, (float)($input['cost_price'] ?? 0));
        $price    = max(0.0, (float)($input['selling_price'] ?? 0));
        $stock    = max(0, (int)($input['stock_qty'] ?? 0));
        $reorder  = max(0, (int)($input['reorder_level'] ?? 10));

        if ($name === '' || $catId <= 0) {
            json_out(['success' => false, 'message' => 'Product name and category are required.']);
        }
        if (preg_match('/[^A-Za-z0-9 \-.()\/"ñÑ]/u', $name)) {
            json_out(['success' => false, 'message' => 'Product name may only contain letters, numbers, spaces, dots, hyphens, slashes and parentheses.']);
        }
        $st = db()->prepare('SELECT id, name FROM categories WHERE id = ?');
        $st->execute([$catId]);
        $catRow = $st->fetch();
        if (!$catRow) {
            json_out(['success' => false, 'message' => 'Category does not exist.']);
        }
        

        
        if ($barcode === '') {
            $catStmt = db()->prepare('SELECT name FROM categories WHERE id = ?');
            $catStmt->execute([$catId]);
            $words = preg_split('/[^A-Za-z]+/', (string)$catStmt->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
            $prefix = $words ? strtoupper(substr(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)), 0, 3)) : 'GEN';
            $chk = db()->prepare('SELECT id FROM products WHERE barcode = ?');
            do {
                $barcode = sprintf('MH-%s-%05d', $prefix, random_int(0, 99999));
                $chk->execute([$barcode]);
            } while ($chk->fetch());
        } else {
            
            $chk = db()->prepare('SELECT id FROM products WHERE barcode = ? AND id != ?');
            $chk->execute([$barcode, $id]);
            if ($chk->fetch()) {
                json_out(['success' => false, 'message' => 'Barcode/SKU "' . $barcode . '" already belongs to another product.']);
            }
        }

        if ($id > 0) {
            $st = db()->prepare(
                'UPDATE products SET barcode = ?, name = ?, category_id = ?, unit = ?, image = ?, cost_price = ?,
                        selling_price = ?, stock_qty = ?, reorder_level = ? WHERE id = ?'
            );
            $st->execute([$barcode ?: null, $name, $catId, $unit, ($input['image'] ?? null), $cost, $price, $stock, $reorder, $id]);
            audit_log($user, 'product_update', 'product', $id, 'Updated product ' . $name);
            json_out(['success' => true, 'message' => 'Product updated.']);
        }

        
        $st = db()->prepare(
            'INSERT INTO products (barcode, name, category_id, unit, image, cost_price, selling_price, stock_qty, display_qty, reorder_level)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $imgPath = $input['image'] ?? null;
        $st->execute([$barcode ?: null, $name, $catId, $unit, $imgPath, $cost, $price, $stock, $reorder]);
        $newId = (int)db()->lastInsertId();
        
        if (empty($imgPath)) {
            auto_product_image($newId, $name, (string)$catRow['name']);
        }

        if ($stock > 0) {
            $mv = db()->prepare(
                'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "stock_in", ?, "Initial stock")'
            );
            $mv->execute([$newId, $user['id'], $stock]);
        }
        audit_log($user, 'product_create', 'product', $newId, 'Added product ' . $name);
        json_out(['success' => true, 'message' => 'Product added.', 'id' => $newId]);

    case 'delete_product':
        require_role('admin', 'super_admin');
        $id = (int)($input['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM products WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            json_out(['success' => false, 'message' => 'Product not found.']);
        }
        
        $st = db()->prepare('UPDATE products SET status = "inactive" WHERE id = ?');
        $st->execute([$id]);
        audit_log($user, 'product_delete', 'product', $id, 'Archived product ' . $p['name']);
        json_out(['success' => true, 'message' => 'Product archived.']);

    case 'save_category':
        require_role('admin', 'super_admin');
        $id   = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            json_out(['success' => false, 'message' => 'Category name is required.']);
        }
        if ($id > 0) {
            $st = db()->prepare('UPDATE categories SET name = ? WHERE id = ?');
            $st->execute([$name, $id]);
            audit_log($user, 'category_update', 'category', $id, 'Renamed category to ' . $name);
            json_out(['success' => true, 'message' => 'Category updated.']);
        }
        $st = db()->prepare('INSERT INTO categories (name) VALUES (?)');
        try {
            $st->execute([$name]);
        } catch (PDOException $e) {
            json_out(['success' => false, 'message' => 'Category already exists.']);
        }
        audit_log($user, 'category_create', 'category', (int)db()->lastInsertId(), 'Added category ' . $name);
        json_out(['success' => true, 'message' => 'Category added.']);

    case 'delete_category':
        require_role('admin', 'super_admin');
        $id = (int)($input['id'] ?? 0);
        $st = db()->prepare('SELECT COUNT(*) c FROM products WHERE category_id = ? AND status = "active"');
        $st->execute([$id]);
        if ((int)$st->fetch()['c'] > 0) {
            json_out(['success' => false, 'message' => 'Cannot delete: category still has products.']);
        }
        $st = db()->prepare('DELETE FROM categories WHERE id = ?');
        $st->execute([$id]);
        audit_log($user, 'category_delete', 'category', $id, 'Deleted category #' . $id);
        json_out(['success' => true, 'message' => 'Category deleted.']);

    case 'adjust_stock':
        
        require_role('super_admin');
        $id    = (int)($input['product_id'] ?? 0);
        $qty   = (int)($input['qty'] ?? 0);
        $notes = trim($input['notes'] ?? '');
        if (!$id || $qty === 0) {
            json_out(['success' => false, 'message' => 'Product and quantity are required.']);
        }
        $st = db()->prepare('SELECT name, stock_qty FROM products WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            json_out(['success' => false, 'message' => 'Product not found.']);
        }
        $newQty = (int)$p['stock_qty'] + $qty;
        if ($newQty < 0) {
            json_out(['success' => false, 'message' => 'Adjustment would make stock negative.']);
        }
        $st = db()->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
        $st->execute([$newQty, $id]);
        $mv = db()->prepare(
            'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "adjustment", ?, ?)'
        );
        $mv->execute([$id, $user['id'], $qty, $notes ?: 'Manual adjustment']);
        audit_log($user, 'stock_adjust', 'product', $id, 'Adjusted ' . $p['name'] . ' by ' . $qty . ' (' . $notes . ')');
        check_low_stock($id);
        json_out(['success' => true, 'message' => 'Warehouse stock adjusted to ' . $newQty . '.']);

    case 'transfer_stock':
        
        require_role('super_admin');
        $pid       = (int)($input['product_id'] ?? 0);
        $direction = ($input['direction'] ?? '') === 'to_warehouse' ? 'to_warehouse' : 'to_display';
        $tQty      = (int)($input['qty'] ?? 0);
        if (!$pid || $tQty <= 0) {
            json_out(['success' => false, 'message' => 'Product and a positive quantity are required.']);
        }
        $st = db()->prepare('SELECT name, stock_qty, display_qty FROM products WHERE id = ? AND status = "active"');
        $st->execute([$pid]);
        $prod = $st->fetch();
        if (!$prod) {
            json_out(['success' => false, 'message' => 'Product not found.']);
        }
        if ($direction === 'to_display' && (int)$prod['stock_qty'] < $tQty) {
            json_out(['success' => false, 'message' => 'Not enough warehouse stock (only ' . $prod['stock_qty'] . ').']);
        }
        if ($direction === 'to_warehouse' && (int)$prod['display_qty'] < $tQty) {
            json_out(['success' => false, 'message' => 'Not enough display stock (only ' . $prod['display_qty'] . ').']);
        }
        if ($direction === 'to_display') {
            $st = db()->prepare('UPDATE products SET stock_qty = stock_qty - ?, display_qty = display_qty + ? WHERE id = ?');
        } else {
            $st = db()->prepare('UPDATE products SET display_qty = display_qty - ?, stock_qty = stock_qty + ? WHERE id = ?');
        }
        $st->execute([$tQty, $tQty, $pid]);
        $mv = db()->prepare(
            'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "adjustment", ?, ?)'
        );
        $mv->execute([$pid, $user['id'], $tQty, 'Transfer ' . ($direction === 'to_display' ? 'warehouse to display' : 'display to warehouse') . ' — ' . ($input['notes'] ?? '')]);
        audit_log($user, 'stock_transfer', 'product', $pid, $tQty . ' units ' . $direction . ' — ' . $prod['name']);
        json_out(['success' => true, 'message' => 'Transferred ' . $tQty . ' ' . $direction . '.']);

    case 'receive_stock':
        
        require_role('super_admin');
        $in    = json_input();
        $items = $in['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            json_out(['success' => false, 'message' => 'No items to receive.']);
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
            $mv = $pdo->prepare('INSERT INTO inventory_movements (product_id, user_id, type, qty, notes) VALUES (?, ?, "stock_in", ?, ?)');
            $added = 0;
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = max(1, (int)($it['qty'] ?? 0));
                if (!$pid || !$qty) continue;
                $up->execute([$qty, $pid]);
                $mv->execute([$pid, $user['id'], $qty, 'Received via barcode scan']);
                $added++;
            }
            if (!$added) throw new RuntimeException('Nothing valid to receive.');
            $pdo->commit();
            audit_log($user, 'stock_received', 'product', null, 'Scan receive: ' . $added . ' product(s) added to warehouse stock');
            json_out(['success' => true, 'message' => 'Stock received — warehouse quantities updated.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['success' => false, 'message' => 'Receive failed: ' . $e->getMessage()], 500);
        }

    default:
        json_out(['success' => false, 'message' => 'Unknown action'], 400);
}
