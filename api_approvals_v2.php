<?php
/**
 * MARINA'S HARDWARE POS - Approvals API v2 (stock requests workflow)
 *
 * Flow:
 *   Cashier/admin files a stock request (stock_in / price_change / damage_report)
 *     -> notification to approvers (admin + super_admin)
 *   Admin or super admin decides (approve / reject)
 *     -> on approve: applies the change to inventory + logs movement
 *     -> notification back to the requester
 *
 * GET  : my_requests | queue | history
 * POST : create | decide
 */
require __DIR__ . '/db.php';

$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input  = $method === 'POST' ? json_input() : [];

function request_row(string $where, array $params): array
{
    $st = db()->prepare(
        'SELECT sr.*, p.name AS product_name, p.unit, p.stock_qty, p.selling_price,
                ru.full_name AS requested_by_name, du.full_name AS decided_by_name
           FROM stock_requests sr
           LEFT JOIN products p  ON p.id  = sr.product_id
           JOIN users ru         ON ru.id = sr.requested_by
           LEFT JOIN users du    ON du.id = sr.decided_by
           ' . $where . '
          ORDER BY sr.id DESC LIMIT 300'
    );
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        if (!empty($r['payload'])) {
            $r['payload_data'] = json_decode($r['payload'], true);
            // new-product requests have no joined product yet — show the requested name
            if (empty($r['product_name']) && is_array($r['payload_data'] ?? null)) {
                $r['product_name'] = $r['payload_data']['new']['name'] ?? $r['payload_data']['name'] ?? '(new product)';
            }
        }
    }
    return $rows;
}

if ($method === 'GET') {
    switch ($action) {

        case 'my_requests': // requests I filed, still pending
            json_out(['success' => true, 'requests' => request_row(
                'WHERE sr.requested_by = ? AND sr.status = "pending"',
                [$user['id']]
            )]);

        case 'queue': // pending requests waiting for a decision — SUPER ADMIN ONLY
            require_role('super_admin');
            json_out(['success' => true, 'requests' => request_row('WHERE sr.status = "pending"', [])]);

        case 'history':
            require_role('admin', 'super_admin');
            if ($user['role'] === 'admin') {
                // admins see only the requests they filed (all statuses)
                $rows = request_row('WHERE sr.requested_by = ?', [$user['id']]);
            } else {
                // super admin sees everything
                $rows = request_row('', []);
            }
            json_out(['success' => true, 'requests' => $rows]);

        default:
            json_out(['success' => false, 'message' => 'Unknown action'], 400);
    }
}

switch ($action) {

    /* ---------------- CREATE REQUEST (admins only) ---------------- */
    case 'create':
        require_role('admin');
        $productId = (int)($input['product_id'] ?? 0);
        $reqType   = in_array($input['req_type'] ?? '', ['stock_in', 'price_change', 'damage_report', 'new_product', 'edit_product', 'adjust_stock', 'transfer_stock'], true)
                        ? $input['req_type'] : 'stock_in';
        $qty       = (int)($input['qty'] ?? 0);
        $newPrice  = isset($input['new_price']) && $input['new_price'] !== null ? (float)$input['new_price'] : null;
        $reason    = trim($input['reason'] ?? '');
        $payload   = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : null;

        /* --- product add / edit requests carry the full details as payload --- */
        if ($reqType === 'new_product' || $reqType === 'edit_product') {
            if ($reqType === 'edit_product') {
                $editPid = (int)($payload['product_id'] ?? 0);
                $data    = is_array($payload['new'] ?? null) ? $payload['new'] : null;
                if (!$data || !$editPid) {
                    json_out(['success' => false, 'message' => 'Missing product details.']);
                }
                $st = db()->prepare('SELECT id FROM products WHERE id = ?');
                $st->execute([$editPid]);
                if (!$st->fetch()) {
                    json_out(['success' => false, 'message' => 'Product no longer exists.']);
                }
            } else {
                $editPid = 0;
                $data    = $payload;
            }
            $name  = trim($data['name'] ?? '');
            $catId = (int)($data['category_id'] ?? 0);
            if ($name === '' || $catId <= 0) {
                json_out(['success' => false, 'message' => 'Product name and category are required.']);
            }
            if (preg_match('/[^A-Za-z0-9 \-.()\/"ñÑ]/u', $name)) {
                json_out(['success' => false, 'message' => 'Product name may only contain letters, numbers, spaces, dots, hyphens, slashes and parentheses.']);
            }
            $st = db()->prepare('SELECT id FROM categories WHERE id = ?');
            $st->execute([$catId]);
            if (!$st->fetch()) {
                json_out(['success' => false, 'message' => 'Category does not exist.']);
            }
            /* numeric fields can never be negative — clamp before storing/applying */
            $data['cost_price']    = max(0, (float)($data['cost_price'] ?? 0));
            $data['selling_price'] = max(0, (float)($data['selling_price'] ?? 0));
            $data['stock_qty']     = max(0, (int)($data['stock_qty'] ?? 0));
            $data['reorder_level'] = max(0, (int)($data['reorder_level'] ?? 10));
            if ($reqType === 'edit_product') {
                $payload['new'] = $data;   // keep the payload consistent with what will be applied
            } else {
                $payload = $data;          // new_product: the payload IS the product data
            }
            $barcode = trim($data['barcode'] ?? '');
            if ($barcode !== '') {
                $chk = db()->prepare('SELECT id FROM products WHERE barcode = ?' .
                    ($reqType === 'edit_product' ? ' AND id != ' . $editPid : ''));
                $chk->execute([$barcode]);
                if ($chk->fetch()) {
                    json_out(['success' => false, 'message' => 'Barcode/SKU "' . $barcode . '" already belongs to another product.']);
                }
            }
            if ($reqType === 'edit_product') {
                $chk2 = db()->prepare('SELECT id FROM stock_requests WHERE req_type = "edit_product" AND product_id = ? AND status = "pending"');
                $chk2->execute([$editPid]);
                if ($chk2->fetch()) {
                    json_out(['success' => false, 'message' => 'A change request for this product is already pending approval.']);
                }
            }

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $st = db()->prepare(
                'INSERT INTO stock_requests (product_id, requested_by, req_type, qty, new_price, payload, reason)
                 VALUES (?, ?, ?, 0, NULL, ?, ?)'
            );
            $st->execute([
                $reqType === 'edit_product' ? $editPid : null,
                $user['id'], $reqType, $payloadJson, $reason ?: ($reqType === 'new_product' ? 'New product' : 'Product update'),
            ]);
            $reqId = (int)db()->lastInsertId();

            $what = $reqType === 'new_product'
                ? 'NEW PRODUCT "' . $name . '" (₱' . number_format((float)($data['selling_price'] ?? 0), 2) . ', ' . ($data['stock_qty'] ?? 0) . ' ' . ($data['unit'] ?? 'pc') . ' initial stock)'
                : 'CHANGE to "' . $name . '" (see details in Approvals)';
            notify('role:super_admin', 'approval', $reqType === 'new_product' ? 'New product needs approval' : 'Product change needs approval',
                $user['full_name'] . ' filed: ' . $what, 'super_admin.php');
            audit_log($user, 'request_create', 'stock_request', $reqId, $what);

            json_out(['success' => true, 'message' => 'Request #' . $reqId . ' submitted for approval.', 'id' => $reqId]);
        }

        /* --- stock adjustment requests (signed qty: + add, − deduct) --- */
        if ($reqType === 'adjust_stock') {
            $pid    = (int)($payload['product_id'] ?? 0);
            $adjQty = (int)($payload['qty'] ?? 0);
            if (!$pid || $adjQty === 0) {
                json_out(['success' => false, 'message' => 'Product and a non-zero adjustment are required.']);
            }
            $st = db()->prepare('SELECT name, stock_qty FROM products WHERE id = ? AND status = "active"');
            $st->execute([$pid]);
            $prod = $st->fetch();
            if (!$prod) {
                json_out(['success' => false, 'message' => 'Product not found.']);
            }
            if ((int)$prod['stock_qty'] + $adjQty < 0) {
                json_out(['success' => false, 'message' => 'Adjustment would make the stock negative (current: ' . $prod['stock_qty'] . ').']);
            }
            $chk2 = db()->prepare('SELECT id FROM stock_requests WHERE req_type = "adjust_stock" AND product_id = ? AND status = "pending"');
            $chk2->execute([$pid]);
            if ($chk2->fetch()) {
                json_out(['success' => false, 'message' => 'An adjustment for this product is already pending approval.']);
            }

            $payloadJson = json_encode([
                'product_id' => $pid, 'qty' => $adjQty, 'current_stock' => (int)$prod['stock_qty'],
            ], JSON_UNESCAPED_UNICODE);
            $st = db()->prepare(
                'INSERT INTO stock_requests (product_id, requested_by, req_type, qty, payload, reason)
                 VALUES (?, ?, "adjust_stock", ?, ?, ?)'
            );
            $st->execute([$pid, $user['id'], $adjQty, $payloadJson, $reason ?: 'Stock adjustment']);
            $reqId = (int)db()->lastInsertId();

            $sign = $adjQty > 0 ? '+' : '';
            notify('role:super_admin', 'approval', 'Stock adjustment needs approval',
                $user['full_name'] . ' wants to adjust "' . $prod['name'] . '" by ' . $sign . $adjQty .
                ' (current stock: ' . $prod['stock_qty'] . ')', 'super_admin.php');
            audit_log($user, 'request_create', 'stock_request', $reqId,
                'Stock adjustment ' . $sign . $adjQty . ' on ' . $prod['name'] . ' — ' . ($reason ?: 'no reason'));
            json_out(['success' => true, 'message' => 'Request #' . $reqId . ' submitted for approval.', 'id' => $reqId]);
        }

        /* --- stock transfer requests (warehouse <-> display shelves) --- */
        if ($reqType === 'transfer_stock') {
            $pid       = (int)($payload['product_id'] ?? 0);
            $direction = ($payload['direction'] ?? '') === 'to_warehouse' ? 'to_warehouse' : 'to_display';
            $tQty      = (int)($payload['qty'] ?? 0);
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
            $chk2 = db()->prepare('SELECT id FROM stock_requests WHERE req_type = "transfer_stock" AND product_id = ? AND status = "pending"');
            $chk2->execute([$pid]);
            if ($chk2->fetch()) {
                json_out(['success' => false, 'message' => 'A transfer for this product is already pending approval.']);
            }

            $payloadJson = json_encode([
                'product_id' => $pid, 'direction' => $direction, 'qty' => $tQty,
                'current_stock' => (int)$prod['stock_qty'], 'current_display' => (int)$prod['display_qty'],
            ], JSON_UNESCAPED_UNICODE);
            $st = db()->prepare(
                'INSERT INTO stock_requests (product_id, requested_by, req_type, qty, payload, reason)
                 VALUES (?, ?, "transfer_stock", ?, ?, ?)'
            );
            $st->execute([$pid, $user['id'], $tQty, $payloadJson, $reason ?: 'Stock transfer']);
            $reqId = (int)db()->lastInsertId();

            $where = $direction === 'to_display' ? 'to the display shelves' : 'back to the warehouse';
            notify('role:super_admin', 'approval', 'Stock transfer needs approval',
                $user['full_name'] . ' wants to move ' . $tQty . ' units of "' . $prod['name'] . '" ' . $where .
                ' (warehouse: ' . $prod['stock_qty'] . ', display: ' . $prod['display_qty'] . ')', 'super_admin.php');
            audit_log($user, 'request_create', 'stock_request', $reqId,
                'Transfer ' . $tQty . ' of ' . $prod['name'] . ' ' . $where);
            json_out(['success' => true, 'message' => 'Request #' . $reqId . ' submitted for approval.', 'id' => $reqId]);
        }

        /* --- stock-in / price change / damage report --- */
        if (!$productId) {
            json_out(['success' => false, 'message' => 'Please choose a product.']);
        }
        $st = db()->prepare('SELECT name, stock_qty, reorder_level FROM products WHERE id = ? AND status = "active"');
        $st->execute([$productId]);
        $p = $st->fetch();
        if (!$p) {
            json_out(['success' => false, 'message' => 'Product not found.']);
        }

        if ($reqType === 'stock_in' && $qty <= 0) {
            json_out(['success' => false, 'message' => 'Quantity must be greater than zero for a stock-in request.']);
        }
        if ($reqType === 'damage_report' && $qty <= 0) {
            json_out(['success' => false, 'message' => 'Enter how many units were damaged/lost.']);
        }
        if ($reqType === 'price_change' && (!$newPrice || $newPrice <= 0)) {
            json_out(['success' => false, 'message' => 'Enter the new selling price.']);
        }
        if ($reason === '') {
            json_out(['success' => false, 'message' => 'Please give a short reason.']);
        }

        $st = db()->prepare(
            'INSERT INTO stock_requests (product_id, requested_by, req_type, qty, new_price, reason)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$productId, $user['id'], $reqType, abs($qty), $newPrice, $reason]);
        $reqId = (int)db()->lastInsertId();

        $labels = ['stock_in' => 'Stock-in request', 'price_change' => 'Price change request', 'damage_report' => 'Damage report'];
        $detail = $labels[$reqType] . ' for ' . $p['name'] .
            ($reqType === 'price_change' ? ' -> ' . peso((float)$newPrice) : ' (' . abs($qty) . ' units)');

        // approvals belong to the SUPER ADMIN only — never mirrored to admins
        notify('role:super_admin', 'approval', 'New request needs approval',
            $user['full_name'] . ': ' . $detail, 'super_admin.php');
        audit_log($user, 'request_create', 'stock_request', $reqId, $detail . ' — ' . $reason);

        json_out(['success' => true, 'message' => 'Request submitted for approval.', 'id' => $reqId]);

    /* ---------------- DECIDE (approve / reject) — SUPER ADMIN ONLY ---------------- */
    case 'decide':
        require_role('super_admin');
        $id     = (int)($input['id'] ?? 0);
        $decision = ($input['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $note   = trim($input['note'] ?? '');

        $st = db()->prepare(
            'SELECT sr.*, p.name AS product_name, p.stock_qty, p.display_qty, p.selling_price, p.cost_price
               FROM stock_requests sr LEFT JOIN products p ON p.id = sr.product_id
              WHERE sr.id = ? AND sr.status = "pending"'
        );
        $st->execute([$id]);
        $req = $st->fetch();
        if (!$req) {
            json_out(['success' => false, 'message' => 'Request not found or already decided.']);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($decision === 'approved') {
                // apply the requested change
                if ($req['req_type'] === 'stock_in') {
                    $newQty = (int)$req['stock_qty'] + (int)$req['qty'];
                    $upd = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
                    $upd->execute([$newQty, $req['product_id']]);
                    $mv = $pdo->prepare(
                        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
                         VALUES (?, ?, "stock_in", ?, ?)'
                    );
                    $mv->execute([$req['product_id'], $user['id'], (int)$req['qty'],
                                  'Approved request #' . $id . ' — ' . $req['reason']]);
                } elseif ($req['req_type'] === 'price_change') {
                    $upd = $pdo->prepare('UPDATE products SET selling_price = ? WHERE id = ?');
                    $upd->execute([(float)$req['new_price'], $req['product_id']]);
                } elseif ($req['req_type'] === 'damage_report') {
                    $newQty = max(0, (int)$req['stock_qty'] - (int)$req['qty']);
                    $upd = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
                    $upd->execute([$newQty, $req['product_id']]);
                    $mv = $pdo->prepare(
                        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
                         VALUES (?, ?, "adjustment", ?, ?)'
                    );
                    $mv->execute([$req['product_id'], $user['id'], -(int)$req['qty'],
                                  'Damage approved request #' . $id . ' — ' . $req['reason']]);
                } elseif ($req['req_type'] === 'adjust_stock') {
                    // signed shelf correction: + adds display stock, − deducts; never below zero
                    $adjQty = (int)$req['qty'];
                    $cur    = (int)$req['display_qty'];
                    $newQty = $cur + $adjQty;
                    if ($newQty < 0) {
                        throw new RuntimeException('Applying this adjustment would make the display stock negative (current: ' . $cur . ').');
                    }
                    $upd = $pdo->prepare('UPDATE products SET display_qty = ? WHERE id = ?');
                    $upd->execute([$newQty, $req['product_id']]);
                    $mv = $pdo->prepare(
                        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
                         VALUES (?, ?, "adjustment", ?, ?)'
                    );
                    $mv->execute([$req['product_id'], $user['id'], $adjQty,
                                  'Display stock correction approved request #' . $id . ' — ' . $req['reason']]);
                } elseif ($req['req_type'] === 'transfer_stock') {
                    // move units between warehouse and display shelves
                    $pd = json_decode((string)$req['payload'], true);
                    $tQty = (int)($pd['qty'] ?? 0);
                    $toDisplay = ($pd['direction'] ?? '') === 'to_display';
                    $cur = $pdo->prepare('SELECT stock_qty, display_qty FROM products WHERE id = ?');
                    $cur->execute([$req['product_id']]);
                    $prodRow = $cur->fetch();
                    if (!$prodRow) throw new RuntimeException('Product no longer exists.');
                    if ($toDisplay) {
                        if ((int)$prodRow['stock_qty'] < $tQty) {
                            throw new RuntimeException('Not enough warehouse stock (only ' . $prodRow['stock_qty'] . ').');
                        }
                        $upd = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ?, display_qty = display_qty + ? WHERE id = ?');
                        $upd->execute([$tQty, $tQty, $req['product_id']]);
                    } else {
                        if ((int)$prodRow['display_qty'] < $tQty) {
                            throw new RuntimeException('Not enough display stock (only ' . $prodRow['display_qty'] . ').');
                        }
                        $upd = $pdo->prepare('UPDATE products SET display_qty = display_qty - ?, stock_qty = stock_qty + ? WHERE id = ?');
                        $upd->execute([$tQty, $tQty, $req['product_id']]);
                    }
                    $mv = $pdo->prepare(
                        'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
                         VALUES (?, ?, "adjustment", ?, ?)'
                    );
                    $mv->execute([$req['product_id'], $user['id'], $tQty,
                                  'Transfer ' . ($toDisplay ? 'warehouse to display' : 'display to warehouse') . ' approved request #' . $id]);
                } elseif ($req['req_type'] === 'new_product') {
                    // insert the requested product
                    $pd = json_decode((string)$req['payload'], true);
                    $name  = trim($pd['name'] ?? '');
                    $catId = (int)($pd['category_id'] ?? 0);
                    if ($name === '' || !$catId) {
                        throw new RuntimeException('Request payload has invalid product data.');
                    }
                    $barcode = trim($pd['barcode'] ?? '');
                    $chk = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND barcode != ""');
                    if ($barcode !== '') { $chk->execute([$barcode]); if ($chk->fetch()) $barcode = ''; }
                    if ($barcode === '') {
                        $catStmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
                        $catStmt->execute([$catId]);
                        $words = preg_split('/[^A-Za-z]+/', (string)$catStmt->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
                        $prefix = $words ? strtoupper(substr(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)), 0, 3)) : 'GEN';
                        $chk2 = $pdo->prepare('SELECT id FROM products WHERE barcode = ?');
                        do { $barcode = sprintf('MH-%s-%05d', $prefix, random_int(0, 99999)); $chk2->execute([$barcode]); } while ($chk2->fetch());
                    }
                    $stock = max(0, (int)($pd['stock_qty'] ?? 0));
                    $ins = $pdo->prepare(
                        'INSERT INTO products (barcode, name, category_id, unit, image, cost_price, selling_price, stock_qty, display_qty, reorder_level)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
                    );
                    $ins->execute([$barcode, $name, $catId, $pd['unit'] ?? 'pc', ($pd['image'] ?? null),
                                   (float)($pd['cost_price'] ?? 0), (float)($pd['selling_price'] ?? 0),
                                   $stock, max(0, (int)($pd['reorder_level'] ?? 10))]);
                    $newPid = (int)$pdo->lastInsertId();
                    /* awtomatikong litrato kung wlang na-upload */
                    if (empty($pd['image'])) {
                        auto_product_image($newPid, $name, (string)($pd['category_name'] ?? ''));
                    }
                    if ($stock > 0) {
                        $mv = $pdo->prepare(
                            'INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
                             VALUES (?, ?, "stock_in", ?, ?)'
                        );
                        $mv->execute([$newPid, $user['id'], $stock, 'Initial stock — approved request #' . $id]);
                    }
                } elseif ($req['req_type'] === 'edit_product') {
                    // apply the requested field changes — but NEVER stock_qty here:
                    // sales may have changed it since the request was filed.
                    $pd  = json_decode((string)$req['payload'], true);
                    $new = is_array($pd['new'] ?? null) ? $pd['new'] : null;
                    $pid = (int)($pd['product_id'] ?? 0);
                    if (!$new || !$pid) {
                        throw new RuntimeException('Request payload has invalid product data.');
                    }
                    // keep the current barcode if the requested one is now taken by another product
                    $barcode = trim($new['barcode'] ?? '');
                    $cur = $pdo->prepare('SELECT barcode FROM products WHERE id = ?');
                    $cur->execute([$pid]);
                    $current = $cur->fetch();
                    if (!$current) throw new RuntimeException('Product no longer exists.');
                    if ($barcode !== '') {
                        $chk = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND id != ?');
                        $chk->execute([$barcode, $pid]);
                        if ($chk->fetch()) $barcode = $current['barcode'];
                    } else {
                        $barcode = $current['barcode'];
                    }
                    $upd = $pdo->prepare(
                        'UPDATE products SET name = ?, barcode = ?, category_id = ?, unit = ?, image = ?, cost_price = ?, selling_price = ?, reorder_level = ? WHERE id = ?'
                    );
                    $upd->execute([
                        trim($new['name'] ?? '') ?: (string)$req['product_name'], $barcode, max(1, (int)($new['category_id'] ?? 1)),
                        $new['unit'] ?? 'pc', ($new['image'] ?? null), (float)($new['cost_price'] ?? 0), (float)($new['selling_price'] ?? 0),
                        max(0, (int)($new['reorder_level'] ?? 10)), $pid,
                    ]);
                }
            }

            $upd = $pdo->prepare(
                'UPDATE stock_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?'
            );
            $upd->execute([$decision, $user['id'], mb_substr($note, 0, 255), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['success' => false, 'message' => 'Failed to apply decision: ' . $e->getMessage()], 500);
        }

        $what = match ($req['req_type']) {
            'stock_in'      => 'stock-in of ' . $req['qty'] . ' units',
            'price_change'  => 'price change to ' . peso((float)$req['new_price']),
            'damage_report' => 'damage report of ' . $req['qty'] . ' units',
            'new_product'   => 'new product request',
            'edit_product'  => 'product change request',
            'adjust_stock'  => 'stock adjustment of ' . ($req['qty'] > 0 ? '+' : '') . $req['qty'] . ' units',
            'transfer_stock' => 'stock transfer of ' . $req['qty'] . ' units',
            default         => 'request',
        };

        notify('user:' . $req['requested_by'], $decision === 'approved' ? 'approved' : 'rejected',
            'Request ' . $decision,
            'Your ' . $what . ' for ' . $req['product_name'] . ' was ' . $decision . ' by ' . $user['full_name']
                . ($note ? ' — ' . $note : ''));

        audit_log($user, 'request_' . $decision, 'stock_request', $id,
            'Request #' . $id . ' (' . $what . ') ' . $decision);

        if ($decision === 'approved' && $req['product_id']) {
            check_low_stock((int)$req['product_id']);
        }

        json_out(['success' => true, 'message' => 'Request ' . $decision . '.']);

    default:
        json_out(['success' => false, 'message' => 'Unknown action'], 400);
}
