<?php

require __DIR__ . '/db.php';

$IS_SUPER = $IS_SUPER ?? false;
$user = current_user();

if (!$user) {
    header('Location: Login.php' . (empty($GLOBALS['mh_idle_expired']) ? '' : '?expired=1'));
    exit;
}
if ($user['role'] === 'cashier') {
    header('Location: cashier.php');
    exit;
}
if ($IS_SUPER && $user['role'] !== 'super_admin') {
    header('Location: admin.php');
    exit;
}
$me = [
    'id'        => (int)$user['id'],
    'username'  => $user['username'],
    'full_name' => $user['full_name'],
    'role'      => $user['role'],
];
$pageTitle = $IS_SUPER ? 'Super Admin' : 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?> — Marina's Hardware POS</title>
<link rel="stylesheet" href="Assets/sweetalert2.min.css?v=2">
<link rel="stylesheet" href="Assets/<?= $IS_SUPER ? 'super_admin.css' : 'admin.css' ?>?v=6">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
</head>
<body>
<div id="boot-loader" style="position:fixed;inset:0;background:#f4f7f5;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;z-index:9999;font-family:'Segoe UI',system-ui,sans-serif">
  <div style="font-size:44px;animation:mhspin 1.1s linear infinite;display:inline-block">🔧</div>
  <div style="font-weight:800;color:#14532d;font-size:15px;letter-spacing:.5px">MARINA'S HARDWARE</div>
  <div style="font-size:12.5px;color:#7a8a80">Loading the system…</div>
</div>
<style>@keyframes mhspin{from{transform:rotate(0)}to{transform:rotate(360deg)}}</style>
<div id="app" v-cloak>

  
  <aside class="sidebar" :class="{open: sideOpen}">
    <div class="brand">
      <div class="logo-badge">🔧</div>
      <div>
        <div class="b-name">MARINA'S <span>HARDWARE</span></div>
        <div class="b-sub"><?= $IS_SUPER ? 'SUPER ADMIN PANEL' : 'ADMIN PANEL' ?></div>
      </div>
    </div>

    <nav class="nav">
      <div class="nav-label">{{ t('Main') }}</div>
      <a :class="{active: view==='dashboard'}" @click="go('dashboard')">
        <span class="ico">📊</span> {{ t('Dashboard') }}
      </a>
      <a :class="{active: ['inventory','stocksdisplay'].includes(view)}" @click="invNavOpen = !invNavOpen">
        <span class="ico">📦</span> {{ t('Inventory') }}
        <span style="margin-left:auto;font-size:11px;opacity:.8">{{ invNavOpen ? '▾' : '▸' }}</span>
      </a>
      <template v-if="invNavOpen">
        <a :class="{active: view==='inventory'}" @click="go('inventory')" style="padding-left:36px;font-size:13px">
          <span class="ico">🖥️</span> {{ t('Display') }}
        </a>
        <?php if (!$IS_SUPER): ?>
        <a :class="{active: view==='stocksdisplay'}" @click="go('stocksdisplay')" style="padding-left:36px;font-size:13px">
          <span class="ico">📦</span> {{ t('Stocks (warehouse)') }}
        </a>
        <?php else: ?>
        <a :class="{active: view==='stocksdisplay'}" @click="go('stocksdisplay')" style="padding-left:36px;font-size:13px">
          <span class="ico">📦</span> {{ t('Stocks (warehouse)') }}
        </a>
        <?php endif; ?>
      </template>
      <div class="nav-label">{{ t('Workflow') }}</div>
      <?php if (!$IS_SUPER): ?>
      <a :class="{active: view==='requests'}" @click="go('requests')">
        <span class="ico">📝</span> {{ t('My Requests') }}
        <span class="pill" v-if="counts.myRequests">{{ counts.myRequests }}</span>
      </a>
      <?php else: ?>
      <a :class="{active: view==='approvals'}" @click="go('approvals')">
        <span class="ico">✅</span> {{ t('Approvals') }}
        <span class="pill" v-if="counts.queue">{{ counts.queue }}</span>
      </a>
      <?php endif; ?>
      <div class="nav-label">{{ t('Insights') }}</div>
      <a :class="{active: view==='reports'}" @click="go('reports')">
        <span class="ico">📈</span> {{ t('Reports') }}
      </a>
      <a :class="{active: view==='audit'}" @click="go('audit')">
        <span class="ico">📜</span> {{ t('History Logs') }}
      </a>
      <?php if ($IS_SUPER): ?>
      <div class="nav-label">{{ t('Administration') }}</div>
      <a :class="{active: view==='users'}" @click="go('users')">
        <span class="ico">👥</span> {{ t('Users') }}
      </a>
      <a :class="{active: view==='backup'}" @click="go('backup')">
        <span class="ico">💾</span> {{ t('Backup & Restore') }}
      </a>
      <?php endif; ?>
    </nav>

    <div class="side-foot">Marina's Hardware POS v1.0</div>
  </aside>

  
  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" @click="sideOpen=!sideOpen">☰</button>
      <div>
        <h1>{{ pageTitle }}</h1>
        <div class="crumb">{{ pageCrumb }}</div>
      </div>
      <div class="spacer"></div>

      
      <div class="lang-wrap" :class="{open: langOpen}" @click.stop="langOpen=!langOpen" title="Language / Wika">
        <span class="lang-globe">🌐</span>
        <span class="lang-cur">{{ lang === 'fil' ? 'Filipino' : 'English' }}</span>
        <span class="lang-chev">▾</span>
        <div class="lang-menu" v-if="langOpen" @click.stop>
          <button type="button" class="lang-opt" :class="{on: lang==='en'}" @click="pickLang('en')">English <span class="chk" v-if="lang==='en'">✓</span></button>
          <button type="button" class="lang-opt" :class="{on: lang==='fil'}" @click="pickLang('fil')">Filipino <span class="chk" v-if="lang==='fil'">✓</span></button>
        </div>
      </div>

      
      <div class="bell-wrap">
        <button class="bell-btn" @click.stop="toggleBell" title="Notifications">
          🔔
          <span class="badge" v-if="notif.unread > 0">{{ notif.unread > 99 ? '99+' : notif.unread }}</span>
        </button>
        <div class="bell-menu" v-if="notif.open" @click.stop>
          <div class="bm-head">
            <span>Notifications</span>
            <div style="display:flex;gap:10px">
              <button @click="markAllRead" v-if="notif.unread">Mark all read</button>
              <button @click="clearRead">Clear read</button>
            </div>
          </div>
          <div class="bm-list">
            <div class="bm-empty" v-if="!notif.list.length">
              <div class="big">🔕</div>No notifications yet.<br>Low stock &amp; approval alerts appear here.
            </div>
            <div class="notif-item" v-for="n in notif.list" :key="n.id"
                 :class="{unread: !Number(n.is_read)}" @click="openNotif(n)">
              <div class="notif-ico">{{ notifIcon(n.type) }}</div>
              <div style="min-width:0">
                <div class="n-title">{{ n.title }}</div>
                <div class="n-msg">{{ n.message }}</div>
                <div class="n-time">{{ timeAgo(n.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      
      <div class="user-chip" @click.stop="userMenuOpen=!userMenuOpen">
        <div class="avatar">{{ initials }}</div>
        <div>
          <div class="u-name">{{ me.full_name }}</div>
          <div class="u-role">{{ me.role.replace('_',' ') }}</div>
        </div>
        <div class="user-menu" v-if="userMenuOpen" @click.stop>
          <button class="danger" @click="logout">⏻ Sign out</button>
        </div>
      </div>
    </header>

    <div class="content" @click="sideOpen=false">

      
      <template v-if="view==='dashboard'">
        <div class="stat-grid">
          <div class="stat-card clickable" @click="goSalesReport" :title="t('View Sales Reports')">
            <div class="stat-ico">💰</div>
            <div><div class="s-val">{{ fmtP(dash.todayRevenue) }}</div><div class="s-cap">{{ t("Today's Sales") }} →</div></div>
          </div>
          <div class="stat-card clickable" @click="goSalesReport" :title="t('View Sales Reports')">
            <div class="stat-ico">🧾</div>
            <div><div class="s-val">{{ dash.todayTxns }}</div><div class="s-cap">{{ t('Transactions Today') }} →</div></div>
          </div>
          <div class="stat-card clickable" @click="goLowStock" :title="t('View low stock in Inventory')">
            <div class="stat-ico amber">⚠️</div>
            <div><div class="s-val">{{ dash.lowStock }}</div><div class="s-cap">{{ t('Low Stock Items') }} →</div></div>
          </div>
          <?php if ($IS_SUPER): ?>
          <div class="stat-card clickable" @click="go('approvals')" :title="t('View Approvals')">
            <div class="stat-ico red">⏳</div>
            <div><div class="s-val">{{ counts.queue }}</div><div class="s-cap">{{ t('Pending Approvals') }} →</div></div>
          </div>
          <?php else: ?>
          <div class="stat-card clickable" @click="go('requests')" :title="t('View my requests')">
            <div class="stat-ico red">📝</div>
            <div><div class="s-val">{{ counts.myRequests }}</div><div class="s-cap">{{ t('My Requests Awaiting Approval') }} →</div></div>
          </div>
          <?php endif; ?>
        </div>

        <div class="grid-2">
          <div class="card">
            <div class="card-head">
              <div><h3>{{ t('Sales — last 7 days') }}</h3><div class="sub">{{ t('Revenue & gross profit per day') }}</div></div>
              <button class="btn btn-outline btn-sm" @click="go('reports')">{{ t('View full reports') }} →</button>
            </div>
            <div class="card-body"><div class="chart-box"><canvas id="dashChart"></canvas></div></div>
          </div>

          <div class="card">
            <div class="card-head"><div><h3>{{ t('⚠️ Low stock') }}</h3><div class="sub">{{ t('At or below reorder level') }}</div></div></div>
            <div class="table-wrap">
              <table class="tbl" v-if="dash.lowStockList.length">
                <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Stock') }}</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="p in dash.lowStockList" :key="p.id">
                    <td>
                      <div class="strong">{{ p.name }}</div>
                      <div class="muted">{{ p.category_name }} · reorder at {{ p.reorder_level }}</div>
                    </td>
                    <td class="num"><span class="badge b-red">{{ p.display_qty }} {{ p.unit }}</span></td>
                    <td>
                      <button v-if="!isSuper" class="btn btn-outline btn-sm" @click="openRequest(p.id)">{{ t('Restock') }}</button>
                      <span v-else class="muted">{{ t('adjust in Inventory') }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div class="empty-state" v-else>
                <div class="big">✅</div><div class="t">{{ t('All stocks healthy') }}</div>
                <div class="d">{{ t('No product is at or below its reorder level.') }}</div>
              </div>
            </div>
          </div>
        </div>

        
        <div class="card" v-if="isSuper">
          <div class="card-head">
            <div><h3>{{ t('📦 Inventory Report') }}</h3><div class="sub">{{ t('Fast & slow moving products — summary') }}</div></div>
            <button class="btn btn-outline btn-sm" @click="goToInvReport">{{ t('View full report') }} →</button>
          </div>
          <div class="card-body">
            <div class="kpi-grid" style="margin-bottom:14px">
              <div class="kpi"><div class="k-cap">{{ t('🔥 Fast moving') }}</div><div class="k-val">{{ movement.summary ? movement.summary.fast : '—' }}</div></div>
              <div class="kpi alt2"><div class="k-cap">{{ t('🐢 Slow moving') }}</div><div class="k-val">{{ movement.summary ? movement.summary.slow : '—' }}</div></div>
              <div class="kpi alt3"><div class="k-cap">{{ t('😴 No sales') }} ({{ movement.days }} {{ t('days') }})</div><div class="k-val">{{ movement.summary ? movement.summary.dead : '—' }}</div></div>
              <div class="kpi alt1"><div class="k-cap">{{ t('💰 Stock value sitting (no sales)') }}</div><div class="k-val">{{ fmtP(movement.summary ? movement.summary.deadStockValue : 0) }}</div></div>
            </div>
            <div class="period-pills" style="margin-bottom:14px">
              <button :class="{active: dashMv==='all'}" @click="dashMv='all'">{{ t('📋 All') }}</button>
              <button :class="{active: dashMv==='fast'}" @click="dashMv='fast'">{{ t('🔥 Fast moving') }}</button>
              <button :class="{active: dashMv==='slow'}" @click="dashMv='slow'">{{ t('🐢 Slow moving') }}</button>
              <button :class="{active: dashMv==='dead'}" @click="dashMv='dead'">{{ t('😴 No sales') }}</button>
            </div>
            <div class="grid-2" :style="dashMv==='all' ? '' : 'grid-template-columns:1fr'">
              <div v-if="dashMv==='all' || dashMv==='fast'">
                <div class="strong" style="margin-bottom:8px">{{ t('🔥 Fast movers — top') }} {{ movement.fastList.length }}</div>
                <div class="table-wrap">
                  <table class="tbl">
                    <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Sold') }}</th><th class="num">{{ t('Display left') }}</th></tr></thead>
                    <tbody>
                      <tr v-if="!movement.fastList.length"><td colspan="3" class="muted" style="padding:12px">{{ t('No sales in this period.') }}</td></tr>
                      <tr v-for="p in movement.fastList" :key="'df'+p.id">
                        <td><div class="strong">{{ p.name }}</div><div class="muted">{{ p.category_name }}</div></td>
                        <td class="num"><span class="badge b-green">{{ p.sold30 }} {{ p.unit }}</span></td>
                        <td class="num">{{ p.display_qty }} {{ p.unit }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <div v-if="dashMv!=='fast'">
                <div class="strong" style="margin-bottom:8px">{{ dashMv==='dead' ? t('😴 No sales — top') : t('😴 Sitting stock — top') }} {{ dashSitRows.length }}</div>
                <div class="table-wrap">
                  <table class="tbl">
                    <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Stock value') }}</th><th class="num">{{ t('On display') }}</th></tr></thead>
                    <tbody>
                      <tr v-if="!movement.slowList.length"><td colspan="3" class="muted" style="padding:12px">{{ t('All products have sales. 👍') }}</td></tr>
                      <tr v-for="p in dashSitRows" :key="'ds'+p.id">
                        <td><div class="strong">{{ p.name }}</div><div class="muted">{{ p.movement==='slow' ? '🐢 slow' : '😴 no sales' }}</div></td>
                        <td class="num strong">{{ fmtP(p.stock_value) }}</td>
                        <td class="num">{{ p.display_qty }} {{ p.unit }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        
        <div class="grid-2">
          <div class="card">
            <div class="card-head">
              <div><h3>{{ t('🧾 Latest transactions') }}</h3><div class="sub">{{ t('Latest sales — click to view the receipt') }}</div></div>
              <button class="btn btn-outline btn-sm" @click="goSalesReport">{{ t('View all transactions') }} →</button>
            </div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Cashier') }}</th><th class="num">{{ t('Total') }}</th><th style="text-align:center">Receipt</th></tr></thead>
                <tbody>
                  <tr v-if="!dash.latest.length"><td colspan="4" class="muted" style="padding:14px">{{ t('No sales yet today.') }}</td></tr>
                  <tr v-for="s in dash.latest" :key="s.receipt_no">
                    <td class="strong">{{ s.receipt_no }}</td>
                    <td>{{ s.cashier }}</td>
                    <td class="num strong">{{ fmtP(s.total_amount) }}</td>
                    <td style="text-align:center"><button class="btn btn-outline btn-sm" @click="viewReceiptAdmin(s)">👁 {{ t('View') }}</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="card">
            <div class="card-head">
              <div><h3>{{ t('🏆 Top products') }}</h3><div class="sub">{{ t('Best sellers right now — analytics') }}</div></div>
              <button class="btn btn-outline btn-sm" @click="goSalesReport">{{ t('Full analytics') }} →</button>
            </div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>#</th><th>{{ t('Product') }}</th><th class="num">{{ t('Qty') }}</th><th class="num">{{ t('Revenue') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!dash.topProducts.length"><td colspan="4" class="muted" style="padding:14px">{{ t('No sales yet today.') }}</td></tr>
                  <tr v-for="(p, i) in dash.topProducts" :key="'tp'+i">
                    <td><span class="badge" :class="i < 3 ? 'b-darkgreen' : 'b-gray'">{{ i + 1 }}</span></td>
                    <td class="strong">{{ p.name }}</td>
                    <td class="num">{{ p.qty }}</td>
                    <td class="num strong">{{ fmtP(p.revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>{{ t('🕒 Recent activity') }}</h3><div class="sub">{{ t('Latest actions across the system') }}</div></div></div>
          <div class="table-wrap">
            <table class="tbl" v-if="dash.activity.length">
              <thead><tr><th>{{ t('Time') }}</th><th>{{ t('User') }}</th><th>{{ t('Action') }}</th><th>{{ t('Details') }}</th></tr></thead>
              <tbody>
                <tr v-for="a in dash.activity" :key="a.id">
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(a.created_at) }}</td>
                  <td class="strong">{{ a.user_name || 'System' }}</td>
                  <td><span class="badge b-green">{{ a.action }}</span></td>
                  <td class="muted">{{ a.details }}</td>
                </tr>
              </tbody>
            </table>
            <div class="loading-row" v-else>Loading activity…</div>
          </div>
        </div>
        </div>
      </template>

      
      <template v-if="view==='inventory'">
        <div class="card">
          <div class="card-head">
            <div><h3>📦 Products <span class="badge b-green">{{ invProducts.length }} active</span></h3></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button class="btn btn-outline" @click="printLabels()" title="Print barcode labels — one per product">🏷 {{ t('Barcode labels') }}</button>
              <button class="btn btn-outline" @click="loadInventory(); loadCats()" title="Reload stock from the database">⟳ Refresh</button>
              <button class="btn btn-outline" @click="openCategories">🗂️ Categories</button>
              <button class="btn btn-primary" @click="openProduct()">＋ Add product</button>
            </div>
          </div>
          <div class="card-body">
            <div class="toolbar">
              <div class="search-box">
                <span class="s-ico">🔍</span>
                <input v-model.trim="inv.search" spellcheck="false" autocomplete="off"
                       style="padding-right:34px" placeholder="Search product name or barcode…">
                <button class="clear-btn" v-if="inv.search" @click="clearSearch" title="Clear search"
                        style="position:absolute;right:9px;top:50%;transform:translateY(-50%);border:0;background:#f1f5f9;width:22px;height:22px;border-radius:6px;font-size:11px;cursor:pointer;color:#52636f;line-height:1">✕</button>
              </div>
              <select v-model="inv.catFilter">
                <option :value="0">All categories</option>
                <option v-for="c in inv.cats" :key="c.id" :value="c.id">{{ c.name }} ({{ c.product_count }})</option>
              </select>
              <select v-model="inv.sort" title="Sort products">
                <option value="name_asc">Sort: Name A–Z</option>
                <option value="stock_asc">Sort: Stock lowest first</option>
                <option value="stock_desc">Sort: Stock highest first</option>
                <option value="price_asc">Sort: Price low → high</option>
                <option value="price_desc">Sort: Price high → low</option>
              </select>
              <button class="btn btn-filter" :class="{active: inv.lowOnly}" @click="inv.lowOnly = !inv.lowOnly">
                ⚠️ Low stock only
              </button>
            </div>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>{{ t('Product') }}</th><th>Category</th><th class="num">Cost</th><th class="num">Price</th>
                  <th class="num">🖥️ Display</th><th class="num" v-if="isSuper">📦 Stocks (warehouse)</th><th class="num">Reorder</th><th v-if="isSuper">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="inv.loading"><td colspan="8" style="padding:10px 14px">
                  <div class="skel-row" v-for="i in 6" :key="'skl'+i">
                    <div class="skel" style="width:34px;height:34px;border-radius:8px;flex-shrink:0"></div>
                    <div style="flex:1;min-width:0">
                      <div class="skel" style="height:12px;width:42%;margin-bottom:5px"></div>
                      <div class="skel" style="height:10px;width:26%"></div>
                    </div>
                    <div class="skel" style="width:64px;height:12px"></div>
                    <div class="skel" style="width:64px;height:12px"></div>
                    <div class="skel" style="width:80px;height:12px"></div>
                  </div>
                </td></tr>
                <tr v-else-if="!invProducts.length"><td colspan="8"><div class="empty-state"><div class="big">🖥️</div><div class="t">No products on display</div><div class="d">{{ t('Products on display appear here — use the "+" button to add products.') }}</div></div></td></tr>
                <tr v-for="p in invProducts" :key="p.id">
                  <td>
                    <div style="display:flex;align-items:center;gap:10px">
                      <img v-if="p.image" :src="p.image" loading="lazy" style="width:34px;height:34px;object-fit:cover;border-radius:8px;flex-shrink:0">
                      <span v-else style="font-size:22px">{{ catIcon(p.category_name) }}</span>
                      <div>
                        <div class="strong">{{ p.name }}</div>
                        <div class="muted">{{ p.barcode || 'no barcode' }}</div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge b-green">{{ p.category_name }}</span></td>
                  <td class="num muted">{{ fmtP(p.cost_price) }}</td>
                  <td class="num strong">{{ fmtP(p.selling_price) }}</td>
                  <td class="num">
                    <span :class="['badge', Number(p.is_low) ? 'b-red':'b-green']">
                      {{ p.display_qty }} {{ p.unit }}
                    </span>
                  </td>
                  <td class="num muted">{{ p.reorder_level }}</td>
                  <td class="num" v-if="isSuper"><span class="badge b-gray">{{ p.stock_qty }} {{ p.unit }}</span></td>
                  <td v-if="isSuper" style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" @click="openTransfer(p)">⇄ Transfer</button>
                    <button class="btn btn-outline btn-sm" @click="openAdjust(p)">⇅ Adjust</button>
                    <button class="btn btn-outline btn-sm" @click="openProduct(p)">✏️ Edit</button>
                    <button class="btn btn-danger btn-sm" @click="archiveProduct(p)">{{ t('Delete') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        
        <div class="card">
          <div class="card-head">
            <div><h3>📋 Inventory items</h3><div class="sub">Buong tingnan — stocks at display kada product</div></div>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Product') }}</th><th>Category</th><th class="num">Cost</th><th class="num">Price</th><th class="num">{{ t('Stock') }}</th><th class="num">Reorder</th></tr></thead>
              <tbody>
                <tr v-if="!invDisplayList.length"><td colspan="6" class="loading-row">Loading…</td></tr>
                <tr v-for="p in invDisplayList" :key="'d'+p.id">
                  <td><div class="strong">{{ p.name }}</div><div class="muted">{{ p.barcode }}</div></td>
                  <td><span class="badge b-green">{{ p.category_name }}</span></td>
                  <td class="num muted">{{ fmtP(p.cost_price) }}</td>
                  <td class="num strong">{{ fmtP(p.selling_price) }}</td>
                  <td class="num"><span :class="['badge', Number(p.display_qty) <= Number(p.reorder_level) ? 'b-red' : 'b-green']">{{ p.display_qty }} {{ p.unit }} <span class="muted" style="font-weight:600">display</span></span> · <span class="badge b-gray">{{ p.stock_qty }} {{ p.unit }} <span class="muted" style="font-weight:600">stocks</span></span></td>
                  <td class="num muted">{{ p.reorder_level }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='stocksdisplay'">
        <div class="page-head">
          <div><h2>🏬 Stocks &amp; Display</h2><div class="sub"></div></div>
          <div style="display:flex;gap:10px">
            <button class="btn btn-outline" @click="printLabels()" title="Print barcode labels — one per product">🏷 {{ t('Barcode labels') }}</button>
            <button class="btn btn-outline" @click="loadInventory()" title="Reload from the database">⟳ Refresh</button>
          </div>
        </div>

        <div class="grid-2" style="margin-bottom:14px">
          <div class="card" style="margin:0">
            <div class="card-body">
              <div class="strong" style="margin-bottom:8px">{{ t('🔫 Scan to transfer') }}</div>
              <div class="search-box">
                <span class="s-ico">🔍</span>
                <input v-model.trim="scanBox" @keydown.enter="scanBoxEnter"
                       spellcheck="false" autocomplete="off" :placeholder="t('Scan box barcode, then press Enter…')">
              </div>
              <div class="hint" style="margin-top:7px">{{ isSuper ? t('Moves warehouse stock to display directly.') : t('Files a transfer request for super admin approval.') }}</div>
            </div>
          </div>
          <div class="card" v-if="isSuper" style="margin:0">
            <div class="card-body">
              <div class="strong" style="margin-bottom:8px">{{ t('📥 Receive stock (scan)') }}</div>
              <div class="search-box">
                <span class="s-ico">🔍</span>
                <input v-model.trim="rcv.scan" @keydown.enter="rcvScanEnter"
                       spellcheck="false" autocomplete="off" :placeholder="t('Scan each box — every scan adds 1 box')">
              </div>
              <div class="hint" style="margin-top:7px">{{ t('Confirm below to add the scanned boxes to warehouse stock.') }}</div>
            </div>
          </div>
        </div>

        <div class="card" v-if="isSuper && rcv.items.length" style="margin-bottom:14px">
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Boxes') }}</th><th class="num">{{ t('Units per box') }}</th><th class="num">{{ t('Units to add') }}</th><th style="text-align:center"></th></tr></thead>
              <tbody>
                <tr v-for="it in rcv.items" :key="'rc'+it.id">
                  <td class="strong">{{ it.name }}</td>
                  <td class="num">{{ it.scans }}</td>
                  <td class="num"><input type="number" min="1" v-model.number="it.per_box" style="width:70px;text-align:center;padding:4px 6px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit"></td>
                  <td class="num strong">{{ it.scans * Math.max(1, it.per_box || 1) }} {{ it.unit }}</td>
                  <td style="text-align:center"><button class="btn btn-outline btn-sm" @click="rcvRemove(it.id)">✕</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex;gap:10px;padding:12px 18px">
            <button class="btn btn-primary" @click="confirmReceive" :disabled="rcv.busy">{{ rcv.busy ? t('Saving…') : t('📥 Confirm receive') }}</button>
            <button class="btn btn-outline" @click="rcv.items = []">{{ t('Clear list') }}</button>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <div><h3>🏬 Stocks &amp; Display</h3><div class="sub"></div></div>
            <div class="search-box" style="min-width:240px">
              <span class="s-ico">🔍</span>
              <input v-model.trim="inv.search" spellcheck="false" autocomplete="off"
                     style="padding-right:34px" placeholder="Search product…">
              <button class="clear-btn" v-if="inv.search" @click="clearSearch" title="Clear search"
                      style="position:absolute;right:9px;top:50%;transform:translateY(-50%);border:0;background:#f1f5f9;width:22px;height:22px;border-radius:6px;font-size:11px;cursor:pointer;color:#52636f;line-height:1">✕</button>
            </div>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Product') }}</th><th>Category</th><th class="num">📦 Stocks (warehouse)</th><th class="num">🖥️ Display</th><th style="text-align:center">Actions</th></tr></thead>
              <tbody>
                <tr v-if="inv.loading"><td colspan="5" style="padding:10px 14px">
                  <div class="skel-row" v-for="i in 6" :key="'sks'+i">
                    <div class="skel" style="width:34px;height:34px;border-radius:8px;flex-shrink:0"></div>
                    <div style="flex:1;min-width:0">
                      <div class="skel" style="height:12px;width:45%;margin-bottom:5px"></div>
                      <div class="skel" style="height:10px;width:28%"></div>
                    </div>
                    <div class="skel" style="width:64px;height:12px"></div>
                    <div class="skel" style="width:64px;height:12px"></div>
                  </div>
                </td></tr>
                <tr v-else-if="!invProducts.length"><td colspan="5"><div class="empty-state"><div class="big">🏬</div><div class="t">No products found</div></div></td></tr>
                <tr v-for="p in invProducts" :key="'sd'+p.id">
                  <td>
                    <div style="display:flex;align-items:center;gap:10px">
                      <img v-if="p.image" :src="p.image" loading="lazy" style="width:34px;height:34px;object-fit:cover;border-radius:8px;flex-shrink:0">
                      <span v-else style="font-size:22px">{{ catIcon(p.category_name) }}</span>
                      <div>
                        <div class="strong">{{ p.name }}</div>
                        <div class="muted">{{ p.barcode || 'no barcode' }}</div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge b-green">{{ p.category_name }}</span></td>
                  <td class="num"><span :class="['badge', p.stock_qty > 0 ? 'b-green' : 'b-gray']">{{ p.stock_qty }} {{ p.unit }}</span></td>
                  <td class="num">
                    <span :class="['badge', Number(p.display_qty) <= Number(p.reorder_level) ? 'b-red' : 'b-darkgreen']">{{ p.display_qty }} {{ p.unit }}</span>
                  </td>
                  <td style="white-space:nowrap;text-align:center">
                    <div style="display:inline-flex;gap:6px;justify-content:center;flex-wrap:wrap">
                      <button class="btn btn-outline btn-sm" @click="openTransfer(p)">⇄ Transfer</button>
                      <button class="btn btn-outline btn-sm" @click="openAdjust(p)">⇅ Adjust</button>
                      <button class="btn btn-outline btn-sm" @click="openProduct(p)">✏️ Edit</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='requests'">
        <div class="page-head">
          <div><h2>📝 My stock requests</h2><div class="sub">Requests you filed that are still pending approval</div></div>
          <button class="btn btn-primary" @click="openRequest()">＋ New request</button>
        </div>

        <div class="card">
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>#</th><th>{{ t('Product') }}</th><th>Type</th><th>Detail</th><th>Reason</th><th>Filed</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-if="!req.my.length"><td colspan="8"><div class="empty-state"><div class="big">📝</div><div class="t">No pending requests</div><div class="d">Click "New request" to ask for a stock-in, price change or damage report.</div></div></td></tr>
                <tr v-for="r in req.my" :key="r.id">
                  <td class="muted">#{{ r.id }}</td>
                  <td class="strong">{{ r.product_name }}</td>
                  <td><span class="badge b-gray">{{ reqTypeLabel(r.req_type) }}</span></td>
                  <td class="strong" v-if="r.req_type==='price_change'">{{ fmtP(r.new_price) }}</td>
                  <td class="strong" v-else-if="r.req_type==='new_product'">{{ fmtP(r.payload_data && r.payload_data.selling_price) }} · {{ r.payload_data ? r.payload_data.stock_qty : '' }} {{ r.payload_data ? r.payload_data.unit : '' }}</td>
                  <td class="strong" v-else-if="r.req_type==='edit_product'">{{ editDiffSummary(r.payload_data) }}</td>
                  <td class="num strong" v-else>{{ r.qty }}</td>
                  <td class="muted">{{ r.reason }}</td>
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(r.created_at) }}</td>
                  <td>
                    <span class="badge b-amber">⏳ pending</span>
                    <button class="btn btn-outline btn-sm" style="margin-left:6px" @click="viewRequest(r)">👁 View</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>📕 Past requests</h3><div class="sub">Approved or rejected by the super admin — click 👁 View for details</div></div></div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>#</th><th>{{ t('Product') }}</th><th>Type</th><th>Detail</th><th>Filed</th><th>Decided</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-if="!req.history.length"><td colspan="8"><div class="empty-state"><div class="big">📕</div><div class="t">No decided requests yet</div><div class="d">Approved and rejected requests will appear here.</div></div></td></tr>
                <tr v-for="r in req.history" :key="'h'+r.id">
                  <td class="muted">#{{ r.id }}</td>
                  <td class="strong">{{ r.product_name }}</td>
                  <td><span class="badge b-gray">{{ reqTypeLabel(r.req_type) }}</span></td>
                  <td class="strong" v-if="r.req_type==='price_change'">{{ fmtP(r.new_price) }}</td>
                  <td class="strong" v-else>{{ r.qty }} {{ r.unit }}</td>
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(r.created_at) }}</td>
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(r.decided_at) }}</td>
                  <td><span :class="['badge', r.status==='approved' ? 'b-green' : 'b-red']">{{ r.status }}</span></td>
                  <td><button class="btn btn-outline btn-sm" @click="viewRequest(r)">👁 View</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='approvals'">
        <div class="page-head">
          <div><h2>✅ Approvals</h2><div class="sub">Stock requests waiting for your decision + full history</div></div>
        </div>

        <div class="card">
          <div class="card-head"><h3>⏳ Pending queue ({{ appr.queue.length }})</h3></div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>#</th><th>Requested by</th><th>{{ t('Product') }}</th><th>Type</th><th>Detail</th><th>Reason</th><th>Filed</th><th>Decision</th></tr></thead>
              <tbody>
                <tr v-if="!appr.queue.length"><td colspan="8"><div class="empty-state"><div class="big">🎉</div><div class="t">Nothing to approve</div><div class="d">The pending queue is empty.</div></div></td></tr>
                <tr v-for="r in appr.queue" :key="r.id">
                  <td class="muted">#{{ r.id }}</td>
                  <td class="strong">{{ r.requested_by_name }}</td>
                  <td>
                    {{ r.product_name }}
                    <div class="muted" v-if="r.product_id">current stock: {{ r.stock_qty }} {{ r.unit }}</div>
                  </td>
                  <td><span class="badge b-gray">{{ reqTypeLabel(r.req_type) }}</span></td>
                  <td class="strong" v-if="r.req_type==='price_change'">{{ fmtP(r.selling_price) }} → {{ fmtP(r.new_price) }}</td>
                  <td class="strong" v-else-if="r.req_type==='new_product'">
                    {{ fmtP(r.payload_data && r.payload_data.selling_price) }} ·
                    {{ r.payload_data ? r.payload_data.stock_qty : '' }} {{ r.payload_data ? r.payload_data.unit : '' }}
                  </td>
                  <td class="strong" v-else-if="r.req_type==='edit_product'">{{ editDiffSummary(r.payload_data) }}</td>
                  <td class="strong" v-else>+{{ r.qty }} {{ r.unit }} (stock_in)</td>
                  <td class="muted">{{ r.reason }}</td>
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(r.created_at) }}</td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" @click="viewRequest(r)">👁 View</button>
                    <button class="btn btn-primary btn-sm" @click="decide(r,'approved')">✔ Approve</button>
                    <button class="btn btn-danger btn-sm" @click="decide(r,'rejected')">✖ Reject</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>📕 Decision history</h3><div class="sub">Approved or rejected — click 👁 View to see the full details</div></div></div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>#</th><th>{{ t('Product') }}</th><th>Type</th><th>Requested by</th><th>Decided by</th><th>Decided at</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-if="!appr.history.length"><td colspan="8" class="loading-row">No history yet.</td></tr>
                <tr v-for="r in appr.history" :key="r.id">
                  <td class="muted">#{{ r.id }}</td>
                  <td class="strong">{{ r.product_name }}</td>
                  <td><span class="badge b-gray">{{ reqTypeLabel(r.req_type) }}</span></td>
                  <td>{{ r.requested_by_name }}</td>
                  <td>{{ r.decided_by_name || '—' }}</td>
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(r.decided_at) }}</td>
                  <td>
                    <span :class="['badge', r.status==='approved' ? 'b-green' : 'b-red']">{{ r.status }}</span>
                  </td>
                  <td><button class="btn btn-outline btn-sm" @click="viewRequest(r)">👁 View</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='reports'">
        <div class="page-head no-print">
          <div><h2>{{ rep.kind==='inventory' ? '📦 Inventory Report' : '📈 Sales Reports' }}</h2><div class="sub">{{ rep.kind==='inventory' ? 'Fast & slow moving products — know what to restock and what not to overstock' : 'Daily, weekly, monthly, overall & custom range' }}</div></div>
          <div style="display:flex;gap:10px;align-items:center">
            <select v-if="isSuper" v-model="rep.kind" @change="onReportKind" style="padding:10px 12px;border:1.6px solid var(--line);border-radius:10px;font-family:inherit;font-size:13.5px;background:#fff;cursor:pointer">
              <option value="sales">📈 Sales Report</option>
              <option value="inventory">📦 Inventory Report</option>
            </select>
            <template v-if="rep.kind==='sales'">
              <button class="btn btn-outline" @click="exportCSV">⬇ Export CSV</button>
            </template>
            <button class="btn btn-primary" @click="printReport">🖨 Print</button>
          </div>
        </div>

        
        <div v-if="rep.kind==='inventory' && isSuper">
          <div class="report-controls no-print">
            <div class="period-pills">
              <button :class="{active: movement.days===7}" @click="movement.days=7; loadMovement()">7 days</button>
              <button :class="{active: movement.days===30}" @click="movement.days=30; loadMovement()">30 days</button>
              <button :class="{active: movement.days===90}" @click="movement.days=90; loadMovement()">90 days</button>
            </div>
          </div>

          <div class="kpi-grid">
            <div class="kpi" :class="{ 'no-print': movementTab==='slow' }"><div class="k-cap">🔥 Fast moving</div><div class="k-val">{{ movement.summary ? movement.summary.fast : '—' }}</div></div>
            <div class="kpi alt2" :class="{ 'no-print': movementTab==='fast' }"><div class="k-cap">🐢 Slow moving</div><div class="k-val">{{ movement.summary ? movement.summary.slow : '—' }}</div></div>
            <div class="kpi alt3" :class="{ 'no-print': movementTab!=='graph' }"><div class="k-cap">😴 No sales ({{ movement.days }} days)</div><div class="k-val">{{ movement.summary ? movement.summary.dead : '—' }}</div></div>
            <div class="kpi alt1" :class="{ 'no-print': movementTab!=='graph' }"><div class="k-cap">💰 Stock value sitting (no sales)</div><div class="k-val">{{ fmtP(movement.summary ? movement.summary.deadStockValue : 0) }}</div></div>
          </div>

          <div class="period-pills" style="margin-bottom:16px">
            <button :class="{active: movementTab==='graph'}" @click="setMovementTab('graph')">📊 Graph</button>
            <button :class="{active: movementTab==='fast'}" @click="setMovementTab('fast')">🔥 Fast moving</button>
            <button :class="{active: movementTab==='slow'}" @click="setMovementTab('slow')">🐢 Slow moving</button>
          </div>

          <div class="card" v-if="movementTab==='graph'">
            <div class="card-head">
              <div><h3>📊 Graph — units sold ({{ movement.days }} days)</h3><div class="sub">Green = 🔥 fast · Amber = 🐢 slow · Gray = no sales</div></div>
            </div>
            <div class="card-body"><div class="chart-box" style="height:420px"><canvas id="movementChart"></canvas></div></div>
          </div>

          <div class="card" v-if="movementTab==='graph'">
            <div class="card-head">
              <div><h3>📋 Table</h3><div class="sub">All products — sorted by units sold</div></div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <div class="search-box" style="min-width:200px">
                  <span class="s-ico">🔍</span>
                  <input v-model.trim="mvSearch" spellcheck="false" autocomplete="off" placeholder="Search product…">
                </div>
                <select v-model="mvFilter" style="padding:10px 12px;border:1.6px solid var(--line);border-radius:10px;font-family:inherit;font-size:13.5px;background:#fff;cursor:pointer">
                  <option value="all">All statuses</option>
                  <option value="fast">🔥 Fast only</option>
                  <option value="slow">🐢 Slow only</option>
                  <option value="dead">😴 No sales only</option>
                </select>
              </div>
            </div>
            <div class="table-wrap" style="max-height:520px;overflow-y:auto">
              <table class="tbl">
                <thead><tr><th>#</th><th>{{ t('Product') }}</th><th>Category</th><th class="num">Sold</th><th class="num">🖥️ Display</th><th class="num">📦 Warehouse</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="(p, i) in movementTableRows" :key="'mt'+p.id">
                    <td class="muted">{{ i + 1 }}</td>
                    <td class="strong">{{ p.name }}</td>
                    <td class="muted">{{ p.category_name }}</td>
                    <td class="num strong">{{ p.sold30 }}</td>
                    <td class="num">{{ p.display_qty }} {{ p.unit }}</td>
                    <td class="num">{{ p.stock_qty }} {{ p.unit }}</td>
                    <td>
                      <span v-if="p.movement==='fast'" class="badge b-green">🔥 fast</span>
                      <span v-else-if="p.movement==='slow'" class="badge b-amber">🐢 slow</span>
                      <span v-else class="badge b-gray">😴 no sales</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card" v-if="movementTab==='fast'">
            <div class="card-head"><div><h3>🔥 Fast moving — top movers</h3><div class="sub">These are your best sellers — restock these first</div></div></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Product') }}</th><th>Category</th><th class="num">Sold ({{ movement.days }}d)</th><th class="num">{{ t('Revenue') }}</th><th class="num">Stock left</th></tr></thead>
                <tbody>
                  <tr v-if="!movement.fastList.length"><td colspan="5" class="muted" style="padding:14px">Not enough sales in this period.</td></tr>
                  <tr v-for="p in movement.fastList" :key="'f'+p.id">
                    <td><div class="strong">{{ p.name }}</div><div class="muted">{{ p.barcode }}</div></td>
                    <td><span class="badge b-green">{{ p.category_name }}</span></td>
                    <td class="num"><span class="badge b-green">{{ p.sold30 }} {{ p.unit }}</span></td>
                    <td class="num">{{ fmtP(p.revenue30) }}</td>
                    <td class="num">{{ p.stock_qty }} {{ p.unit }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card" v-if="movementTab==='slow'">
            <div class="card-head"><div><h3>🐢 {{ t('Slow / no sales') }}</h3><div class="sub">Highest sitting stock — avoid overstocking these</div></div></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Product') }}</th><th>Category</th><th class="num">Sold ({{ movement.days }}d)</th><th class="num">{{ t('Stock') }}</th><th class="num">Stock value</th><th>Last sale</th></tr></thead>
                <tbody>
                  <tr v-if="!movement.slowList.length"><td colspan="6" class="muted" style="padding:14px">All products have sales. 👍</td></tr>
                  <tr v-for="p in movement.slowList" :key="'s'+p.id">
                    <td><div class="strong">{{ p.name }}</div><div class="muted">{{ p.barcode }}</div></td>
                    <td><span class="badge b-gray">{{ p.category_name }}</span></td>
                    <td class="num">{{ p.sold30 }}</td>
                    <td class="num">{{ p.stock_qty }} {{ p.unit }}</td>
                    <td class="num strong">{{ fmtP(p.stock_value) }}</td>
                    <td class="muted" style="white-space:nowrap">
                      <span v-if="p.movement==='slow'" class="badge b-amber">🐢 slow</span>
                      <span v-else class="badge b-gray">😴 {{ t('no sales') }}</span>
                      <template v-if="p.days_since !== null"> · {{ p.days_since }} day(s) ago</template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        
        <div v-if="rep.kind==='sales'">
        <div class="report-controls no-print">
          <div class="period-pills">
            <button :class="{active: rep.period==='daily'}" @click="setPeriod('daily')">Daily</button>
            <button :class="{active: rep.period==='weekly'}" @click="setPeriod('weekly')">Weekly</button>
            <button :class="{active: rep.period==='monthly'}" @click="setPeriod('monthly')">Monthly</button>
            <button :class="{active: rep.period==='overall'}" @click="setPeriod('overall')">Overall</button>
            <button :class="{active: rep.period==='custom'}" @click="setPeriod('custom')">Custom</button>
          </div>
          <div class="date-controls">
            <template v-if="rep.period==='daily'">
              <input type="date" v-model="rep.date" @change="loadReport">
            </template>
            <template v-else-if="rep.period==='weekly'">
              <input type="date" v-model="rep.date" @change="loadReport">
              <span class="muted" style="font-size:12.5px">week containing this date (Mon–Sun)</span>
            </template>
            <template v-else-if="rep.period==='monthly'">
              <input type="month" v-model="rep.month" @change="loadReport">
            </template>
            <template v-else-if="rep.period==='custom'">
              <input type="date" v-model="rep.from" @change="loadReport">
              <span style="color:var(--ink-soft)">→</span>
              <input type="date" v-model="rep.to" @change="loadReport">
            </template>
          </div>
        </div>

        <div class="card" style="border-left:4px solid var(--green-600)">
          <div class="card-body report-title">{{ rep.data?.summary?.label || 'Loading…' }}</div>
        </div>

        
        <div class="kpi-grid" v-if="rep.data">
          <div class="kpi"><div class="k-cap">Total Revenue</div><div class="k-val">{{ fmtP(rep.data.summary.revenue) }}</div></div>
          <div class="kpi alt1"><div class="k-cap">Gross Profit</div><div class="k-val">{{ fmtP(rep.data.summary.profit) }}</div></div>
          <div class="kpi alt2"><div class="k-cap">Transactions</div><div class="k-val">{{ rep.data.summary.transactions }}</div></div>
          <div class="kpi alt3"><div class="k-cap">Items Sold</div><div class="k-val">{{ rep.data.summary.items_sold }}</div></div>
          <div class="kpi"><div class="k-cap">Avg per Transaction</div><div class="k-val">{{ fmtP(rep.data.summary.avg_sale) }}</div></div>
          <div class="kpi alt2"><div class="k-cap">Discounts Given</div><div class="k-val">{{ fmtP(rep.data.summary.discounts) }}</div></div>
        </div>

        
        <div class="card">
          <div class="card-head"><div><h3>Sales &amp; profit trend</h3><div class="sub">Bars = revenue · line = gross profit</div></div></div>
          <div class="card-body"><div class="chart-box"><canvas id="repChart"></canvas></div></div>
        </div>

        <div class="grid-2">
          
          <div class="card">
            <div class="card-head"><h3>🏆 Top products</h3></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>#</th><th>{{ t('Product') }}</th><th class="num">{{ t('Qty') }}</th><th class="num">{{ t('Revenue') }}</th><th class="num">Profit</th></tr></thead>
                <tbody>
                  <tr v-if="!rep.data?.top_products?.length"><td colspan="5" class="loading-row">No sales in this period.</td></tr>
                  <tr v-for="(p,i) in rep.data?.top_products" :key="i">
                    <td><span :class="['badge', i<3 ? 'b-darkgreen' : 'b-gray']">{{ i+1 }}</span></td>
                    <td class="strong">{{ p.name }}</td>
                    <td class="num">{{ p.qty }}</td>
                    <td class="num strong">{{ fmtP(p.revenue) }}</td>
                    <td class="num muted">{{ fmtP(p.profit) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          
          <div class="card">
            <div class="card-head"><h3>🧑‍💼 Sales per cashier</h3></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Cashier') }}</th><th class="num">Transactions</th><th class="num">{{ t('Revenue') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!rep.data?.by_cashier?.length"><td colspan="3" class="loading-row">No sales in this period.</td></tr>
                  <tr v-for="c in rep.data?.by_cashier" :key="c.name">
                    <td class="strong">{{ c.name }}</td>
                    <td class="num">{{ c.transactions }}</td>
                    <td class="num strong">{{ fmtP(c.revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="grid-2">
          
          <div class="card">
            <div class="card-head"><h3>🗂 Sales per category</h3></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>Category</th><th class="num">Qty sold</th><th class="num">{{ t('Revenue') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!rep.data?.by_category?.length"><td colspan="3" class="loading-row">No sales in this period.</td></tr>
                  <tr v-for="c in rep.data?.by_category" :key="c.name">
                    <td class="strong">{{ c.name }}</td>
                    <td class="num">{{ c.qty }}</td>
                    <td class="num strong">{{ fmtP(c.revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          
          <div class="card">
            <div class="card-head"><h3>🧾 Recent transactions</h3></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Receipt') }}</th><th>When</th><th>{{ t('Cashier') }}</th><th>Pay</th><th class="num">{{ t('Total') }}</th><th style="text-align:center">Receipt</th></tr></thead>
                <tbody>
                  <tr v-if="!rep.data?.recent?.length"><td colspan="6" class="loading-row">No sales in this period.</td></tr>
                  <tr v-for="s in rep.data?.recent" :key="s.receipt_no">
                    <td class="strong">{{ s.receipt_no }} <span v-if="s.status==='voided'" class="badge b-red" style="margin-left:6px">VOID</span></td>
                    <td class="muted" style="white-space:nowrap">{{ fmtDT(s.created_at) }}</td>
                    <td>{{ s.cashier }}</td>
                    <td><span class="badge" :class="s.status==='voided' ? 'b-red' : 'b-green'">{{ s.payment_method }}</span></td>
                    <td class="num strong">{{ fmtP(s.total_amount) }}</td>
                    <td style="text-align:center"><button class="btn btn-outline btn-sm" @click="viewReceiptAdmin(s)">👁 View</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        
      </template>

      
      <template v-if="view==='audit'">
        <div class="page-head">
          <div><h2>📜 History Logs</h2><div class="sub">Every important action is logged here</div></div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="toolbar">
              <div class="search-box">
                <span class="s-ico">🔍</span>
                <input v-model.trim="audit.search" placeholder="Search user, action or details…" @input="loadAudit">
              </div>
            </div>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Time') }}</th><th>{{ t('User') }}</th><th>{{ t('Action') }}</th><th>Entity</th><th>{{ t('Details') }}</th><th>IP</th></tr></thead>
              <tbody>
                <tr v-if="audit.loading"><td colspan="6" class="loading-row">Loading logs…</td></tr>
                <tr v-else-if="!audit.logs.length"><td colspan="6"><div class="empty-state"><div class="big">🛡️</div><div class="t">No matching logs</div></div></td></tr>
                <tr v-for="a in audit.logs" :key="a.id">
                  <td class="muted" style="white-space:nowrap">{{ fmtDT(a.created_at) }}</td>
                  <td>
                    <div class="strong">{{ a.user_name || 'System' }}</div>
                    <div class="muted">{{ a.user_role?.replace('_',' ') }}</div>
                  </td>
                  <td><span class="badge b-green">{{ a.action }}</span></td>
                  <td class="muted">{{ a.entity }}{{ a.entity_id ? ' #'+a.entity_id : '' }}</td>
                  <td class="muted">{{ a.details }}</td>
                  <td class="muted">{{ a.ip_address }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='users' && isSuper">
        <div class="page-head">
          <div><h2>👥 User Management</h2><div class="sub">{{ users.list.length }} accounts</div></div>
          <button class="btn btn-primary" @click="openUser()">＋ Add user</button>
        </div>
        <div class="card">
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('User') }}</th><th>Role</th><th>Status</th><th>Phone</th><th>Last login</th><th class="num">Sales</th><th>Actions</th></tr></thead>
              <tbody>
                <tr v-if="users.loading"><td colspan="7" class="loading-row">Loading users…</td></tr>
                <tr v-for="u in users.list" :key="u.id">
                  <td>
                    <div class="strong">{{ u.full_name }}</div>
                    <div class="muted">@{{ u.username }}</div>
                  </td>
                  <td><span :class="['badge', u.role==='super_admin' ? 'b-darkgreen' : (u.role==='admin' ? 'b-green' : 'b-gray')]">{{ u.role.replace('_',' ') }}</span></td>
                  <td>
                    <span :class="['badge', u.status==='active' ? 'b-green' : 'b-red']">{{ u.status }}</span>
                  </td>
                  <td class="muted">{{ u.phone || '—' }}</td>
                  <td class="muted" style="white-space:nowrap">{{ u.last_login ? fmtDT(u.last_login) : 'never' }}</td>
                  <td class="num">{{ u.total_sales }}</td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" @click="openUser(u)">✏️</button>
                    <button class="btn btn-outline btn-sm" @click="openResetPass(u)">🔑</button>
                    <button v-if="u.id !== me.id" :class="['btn','btn-sm', u.status==='active' ? 'btn-danger' : 'btn-primary']"
                            @click="toggleUserStatus(u)">
                      {{ u.status==='active' ? '🚫 Deactivate' : '✔ Activate' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      
      <template v-if="view==='backup' && isSuper">
          
          <div class="stat-grid" style="margin-bottom:20px">
            <div class="stat-card">
              <div class="stat-ico">🕐</div>
              <div><div class="s-val" style="font-size:15px">{{ bk.overview.lastBackup }}</div><div class="s-cap">Last Successful Backup</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-ico" :class="bk.overview.autoEnabled ? '' : 'amber'">{{ bk.overview.autoEnabled ? '✅' : '⛔' }}</div>
              <div><div class="s-val" style="font-size:15px">{{ bk.overview.autoEnabled ? 'Enabled' : 'Disabled' }}</div><div class="s-cap">Backup Status</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-ico">🗂️</div>
              <div><div class="s-val">{{ bk.overview.totalFiles }}</div><div class="s-cap">Total Backup Files</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-ico">💾</div>
              <div><div class="s-val">{{ fmtSize(bk.overview.storageUsed) }}</div><div class="s-cap">Storage Used</div></div>
            </div>
          </div>

          
          <div class="card">
            <div class="card-head">
              <div><h3>{{ t('💾 Backup Management') }}</h3><div class="sub">{{ t('Create, download, and manage backups') }}</div></div>
              <div style="display:flex;gap:10px">
                <button class="btn btn-outline" @click="runAutoNow" :disabled="backup.busy" :title="t('Daily automatic backup')">{{ t('🤖 Auto-backup now') }}</button>
                <button class="btn btn-primary" @click="createBackup" :disabled="backup.creating">{{ backup.creating ? t('Creating…') : t('💾 Create Backup Now') }}</button>
              </div>
            </div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('File') }}</th><th class="num">{{ t('Size') }}</th><th>{{ t('Date & Time') }}</th><th>{{ t('Action') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th style="text-align:center">{{ t('Actions') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!backup.list.length"><td colspan="7" class="muted" style="padding:14px">{{ t('No backups yet — click "Create Backup Now" above.') }}</td></tr>
                  <tr v-for="b in backup.list" :key="b.file">
                    <td class="strong">{{ b.file }}</td>
                    <td class="num">{{ fmtSize(b.size) }}</td>
                    <td class="muted">{{ b.date }}</td>
                    <td><span class="badge b-gray">{{ backupActionLabel(b.file) }}</span></td>
                    <td><span class="badge" :class="b.file.includes('auto') ? 'b-green' : 'b-gray'">{{ b.file.includes('auto') ? t('auto') : t('manual') }}</span></td>
                    <td><span class="badge b-green">{{ t('successful') }}</span></td>
                    <td style="text-align:center;white-space:nowrap">
                      <button class="btn btn-outline btn-sm" @click="verifyBackup(b.file)">🔍 {{ t('Verify') }}</button>
                      <button class="btn btn-outline btn-sm" @click="downloadBackup(b.file)">⬇ {{ t('Download') }}</button>
                      <button class="btn btn-outline btn-sm" @click="restoreBackup(b.file)">♻️ {{ t('Restore') }}</button>
                      <button class="btn btn-danger btn-sm" @click="deleteBackup(b.file)">🗑️ {{ t('Delete') }}</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          
          <div class="card">
            <div class="card-head">
              <div><h3>{{ t('🤖 Automatic Backup Settings') }}</h3><div class="sub">{{ t('Automatic backup on a set schedule') }}</div></div>
            </div>
            <div class="card-body">
              <div class="f-grid">
                <div class="f">
                  <label>{{ t('Automatic Backup') }}</label>
                  <select v-model.number="bk.settings.enabled" @change="saveAutoSettings">
                    <option :value="1">{{ t('✅ Enabled') }}</option>
                    <option :value="0">{{ t('⛔ Disabled') }}</option>
                  </select>
                </div>
                <div class="f">
                  <label>{{ t('Frequency') }}</label>
                  <select v-model="bk.settings.frequency" @change="saveAutoSettings">
                    <option value="daily">{{ t('Daily') }}</option>
                    <option value="weekly">{{ t('Weekly') }}</option>
                    <option value="monthly">{{ t('Monthly') }}</option>
                  </select>
                </div>
                <div class="f">
                  <label>{{ t('Retention Period (days)') }}</label>
                  <input type="number" min="1" max="365" v-model.number="bk.settings.retention_days" @change="saveAutoSettings">
                  <div class="hint">{{ t('Auto backups older than the set days will be deleted.') }}</div>
                </div>
                <div class="f">
                  <label>{{ t('Backup Time') }}</label>
                  <input type="time" v-model="bk.settings.time" @change="saveAutoSettings">
                  <div class="hint">{{ t('Recommended: 10:00 PM — after store hours.') }}</div>
                </div>
              </div>
            </div>
          </div>

          
          <div class="card" style="border-left:4px solid var(--amber,#f59e0b)">
            <div class="card-head">
              <div><h3>{{ t('♻️ Restore Management') }}</h3><div class="sub">{{ t('Restore the database from a backup — a safety backup is created first') }}</div></div>
            </div>
            <div class="card-body">
              <div class="toolbar" style="margin-bottom:14px">
                <input type="file" accept=".sql" @change="onRestoreFilePick" style="font-family:inherit;font-size:13px">
                <input v-model.trim="backup.restoreConfirm" placeholder="Type RESTORE to confirm"
                       style="padding:10px 12px;border:1.6px solid var(--line);border-radius:10px;font-family:inherit;font-size:13.5px;width:200px">
                <button class="btn btn-primary" @click="restoreUpload" :disabled="!backup.restoreFile || backup.restoreConfirm !== 'RESTORE' || backup.busy">>{{ t('♻️ Restore Database') }}</button>
              </div>
              <div style="font-size:13px;color:var(--ink-soft);line-height:1.8">
                <b>{{ t('Restore Steps:') }}</b> {{ t('Select Backup → Verify Backup → Create Safety Backup → Confirm Restore') }}<br>
                <span style="color:var(--danger)">⚠️ {{ t('Restoring an old backup may remove transactions, sales, inventory changes, and user updates made after the backup date.') }}</span>
              </div>
            </div>
          </div>

          
          <div class="card">
            <div class="card-head"><div><h3>{{ t('📝 Backup Activity Logs') }}</h3><div class="sub">{{ t('Action · Performed By · Date & Time · Status · Details') }}</div></div></div>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('Action') }}</th><th>Performed By</th><th>Date &amp; Time</th><th>Type</th><th>Status</th><th>{{ t('Details') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!backup.history.length"><td colspan="6" class="muted" style="padding:14px">{{ t('No backup activity yet.') }}</td></tr>
                  <tr v-for="h in backup.history" :key="'bh'+h.id">
                    <td><span class="badge" :class="h.action==='restore' ? 'b-amber' : h.action==='safety' ? 'b-gray' : 'b-green'">{{ h.action }}</span></td>
                    <td>{{ h.created_by }}</td>
                    <td class="muted">{{ fmtDT(h.created_at) }}</td>
                    <td><span class="badge b-gray">{{ h.type }}</span></td>
                    <td><span :class="['badge', h.status==='successful' ? 'b-green' : 'b-red']">{{ h.status }}</span></td>
                    <td class="muted">{{ h.note }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          
          <div class="card" style="border-left:4px solid var(--danger,#dc2626)">
            <div class="card-head">
              <div><h3 style="color:var(--danger)">☠️ {{ t('Danger Zone') }}</h3><div class="sub">{{ t('Super Administrator only — multiple confirmations required') }}</div></div>
            </div>
            <div class="card-body">
              <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-danger" @click="factoryReset">☠️ {{ t('Factory Reset') }}</button>
              </div>
              <div class="hint" style="margin-top:8px">{{ t('Resets the system to its initial state — ALL data is deleted except default accounts.') }}</div>
            </div>
          </div>
      </template>

    </div>
  </div>

  

  
  <!-- receipt preview modal (works from any view) -->
        <div class="modal-mask" v-if="rcptDetail.open" @click.self="rcptDetail.open=false">
          <div class="modal" style="max-width:430px">
            <div class="modal-head">
              <h3>📄 Receipt {{ rcptDetail.receiptNo }}</h3>
              <button class="modal-close" @click="rcptDetail.open=false">✕</button>
            </div>
            <div class="modal-body" style="background:var(--green-50);display:grid;place-items:center">
              <iframe :src="rcptDetail.url" style="width:100%;height:520px;border:1.5px dashed var(--green-200);border-radius:12px;background:#fff" title="Receipt preview"></iframe>
            </div>
            <div class="modal-foot">
              <button class="btn btn-outline" @click="rcptDetail.open=false">Close</button>
            </div>
          </div>
        </div>

  <div class="modal-mask" v-if="reqDetail.open" @click.self="reqDetail.open=false">
    <div class="modal" style="max-width:560px">
      <div class="modal-head">
        <h3>📄 Request #{{ reqDetail.r.id }} — {{ reqTypeLabel(reqDetail.r.req_type) }}</h3>
        <button class="modal-close" @click="reqDetail.open=false">✕</button>
      </div>
      <div class="modal-body" v-if="reqDetail.r">
        <div class="f-grid" style="margin-bottom:14px">
          <div class="f" style="margin:0"><label>Requested by</label><div style="font-weight:700">{{ reqDetail.r.requested_by_name }}</div></div>
          <div class="f" style="margin:0"><label>Filed</label><div style="font-weight:700">{{ fmtDT(reqDetail.r.created_at) }}</div></div>
          <div class="f" style="margin:0"><label>Status</label>
            <div>
              <span v-if="reqDetail.r.status==='pending'" class="badge b-amber">⏳ pending</span>
              <span v-else :class="['badge', reqDetail.r.status==='approved' ? 'b-green' : 'b-red']">{{ reqDetail.r.status }}</span>
            </div>
          </div>
          <div class="f" style="margin:0" v-if="reqDetail.r.decided_by">
            <label>Decided by {{ reqDetail.r.decided_by_name }} · {{ fmtDT(reqDetail.r.decided_at) }}</label>
            <div style="font-weight:700" v-if="reqDetail.r.decision_note">“{{ reqDetail.r.decision_note }}”</div>
            <div class="muted" v-else>no note left</div>
          </div>
        </div>

        
        <table class="tbl" v-if="reqDetail.r.req_type==='new_product' && reqDetail.r.payload_data">
          <tbody>
            <tr><td style="width:38%">Name</td><td class="strong">{{ reqDetail.r.payload_data.name }}</td></tr>
            <tr><td>Barcode / SKU</td><td>{{ reqDetail.r.payload_data.barcode || '(auto-generate on approval)' }}</td></tr>
            <tr><td>Category</td><td>{{ reqDetail.r.payload_data.category_name }}</td></tr>
            <tr><td>Unit</td><td>{{ reqDetail.r.payload_data.unit }}</td></tr>
            <tr><td>Cost price</td><td>{{ fmtP(reqDetail.r.payload_data.cost_price) }}</td></tr>
            <tr><td>Selling price</td><td class="strong">{{ fmtP(reqDetail.r.payload_data.selling_price) }}</td></tr>
            <tr><td>Initial stock</td><td class="strong">{{ reqDetail.r.payload_data.stock_qty }} {{ reqDetail.r.payload_data.unit }}</td></tr>
            <tr><td>Reorder level</td><td>{{ reqDetail.r.payload_data.reorder_level }}</td></tr>
          </tbody>
        </table>

        
        <table class="tbl" v-else-if="reqDetail.r.req_type==='edit_product'">
          <thead><tr><th>Field</th><th>Current</th><th>Requested</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in editDiffRows(reqDetail.r.payload_data)" :key="i">
              <td>{{ row[0] }}</td>
              <td style="text-decoration:line-through;color:var(--danger)">{{ row[1] }}</td>
              <td class="strong" style="color:var(--green-700)">{{ row[2] }}</td>
            </tr>
            <tr v-if="!editDiffRows(reqDetail.r.payload_data).length"><td colspan="3" class="muted">No field changes recorded.</td></tr>
          </tbody>
        </table>

        
        <table class="tbl" v-else>
          <tbody>
            <tr><td style="width:38%">Product</td><td class="strong">{{ reqDetail.r.product_name }}</td></tr>
            <tr v-if="reqDetail.r.req_type==='stock_in'"><td>Quantity to add</td><td class="strong">{{ reqDetail.r.qty }} {{ reqDetail.r.unit }}</td></tr>
            <tr v-if="reqDetail.r.req_type==='damage_report'"><td>Quantity damaged / lost</td><td class="strong">{{ reqDetail.r.qty }} {{ reqDetail.r.unit }}</td></tr>
            <tr v-if="reqDetail.r.req_type==='price_change'"><td>Price change</td><td class="strong">{{ fmtP(reqDetail.r.selling_price) }} → {{ fmtP(reqDetail.r.new_price) }}</td></tr>
            <tr v-if="reqDetail.r.product_id"><td>Current stock</td><td>{{ reqDetail.r.stock_qty }} {{ reqDetail.r.unit }}</td></tr>
          </tbody>
        </table>

        <div class="f" style="margin-top:14px"><label>Reason / note from requester</label>
          <div style="background:var(--green-50);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13.5px">
            {{ reqDetail.r.reason || '—' }}
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="reqDetail.open=false">Close</button>
        <template v-if="isSuper && reqDetail.r && reqDetail.r.status==='pending'">
          <button class="btn btn-danger" @click="reqDetail.open=false; decide(reqDetail.r,'rejected')">✖ Reject</button>
          <button class="btn btn-primary" @click="reqDetail.open=false; decide(reqDetail.r,'approved')">✔ Approve</button>
        </template>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.product" @click.self="modals.product=false">
    <div class="modal wide">
      <div class="modal-head">
        <h3>{{ inv.form.id ? (isSuper ? '✏️ Edit product' : '✏️ Request product change') : (isSuper ? '＋ Add product' : '＋ Request new product') }}</h3>
        <button class="modal-close" @click="modals.product=false">✕</button>
      </div>
      <div class="modal-body">
        <div class="f-grid">
          <div class="f full">
            <label>Product name *</label>
            <input :value="inv.form.name" @input="onNameInput" placeholder="e.g. Portland Cement 40kg">
            <div class="hint">Letters and numbers only — no special characters.</div>
          </div>
          <div class="f full">
            <label>{{ t('Product image (optional — used on POS cards)') }}</label>
            <input type="file" accept="image/png,image/jpeg,image/webp" @change="onImagePick" :disabled="inv.uploading">
            <div class="hint" v-if="inv.uploading">⏳ Uploading image…</div>
            <div class="hint" v-else-if="inv.form.image">✅ Image ready — makikita sa POS cards.</div>
            <div class="hint" v-else>{{ t('If there is no image, the category emoji is used.') }}</div>
            <img v-if="inv.form.image" :src="inv.form.image" style="width:64px;height:64px;object-fit:cover;border-radius:10px;margin-top:8px;display:block">
          </div>
          <div class="f">
            <label>Barcode / SKU</label>
            <div style="display:flex;gap:6px">
              <input v-model.trim="inv.form.barcode" placeholder="auto-generated — scan or edit…" style="flex:1">
              <button class="btn btn-outline btn-sm" type="button" @click="regenSku" title="Generate new SKU">🔄</button>
            </div>
            <div class="hint">Auto-filled. Edit it or press 🔄 to regenerate.</div>
          </div>
          <div class="f">
            <label>Category *</label>
            <select v-model.number="inv.form.category_id">
              <option v-for="c in inv.cats" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="f">
            <label>{{ t('Unit — how is the product counted?') }}</label>
            <select v-model="inv.form.unit">
              <option value="pc">pc — per piece (bulb, hammer, hollow blocks)</option>
              <option value="bag">bag — per sako (cement, sand, gravel)</option>
              <option value="kg">kg — per kilo (nails and other weighed items)</option>
              <option value="box">box — per box (screws, staple wire)</option>
              <option value="can">can — per can (paint, thinner, solvent)</option>
              <option value="roll">roll — per roll (electrical wire, tape)</option>
              <option value="set">set — per complete set (tools, closet set)</option>
              <option value="pair">pair — per pair (gloves)</option>
            </select>
          </div>
          <div class="f">
            <label>Reorder level</label>
            <input type="number" min="0" :value="inv.form.reorder_level" @input="onNumInput($event, 'reorder_level')">
            <div class="hint">Cannot be negative.</div>
          </div>
          <div class="f">
            <label>Cost price (₱)</label>
            <input type="number" min="0" step="0.01" :value="inv.form.cost_price" @input="onNumInput($event, 'cost_price')">
            <div class="hint">Cannot be negative.</div>
          </div>
          <div class="f">
            <label>Selling price (₱) *</label>
            <input type="number" min="0" step="0.01" :value="inv.form.selling_price" @input="onNumInput($event, 'selling_price')">
            <div class="hint">Cannot be negative.</div>
          </div>
          <div class="f" v-if="!inv.form.id">
            <label>Starting stock</label>
            <input type="number" min="0" :value="inv.form.stock_qty" @input="onNumInput($event, 'stock_qty')">
            <div class="hint">Cannot be negative.</div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.product=false">Cancel</button>
        <button class="btn btn-primary" @click="saveProduct" :disabled="inv.saving">
          {{ isSuper ? (inv.saving ? 'Saving…' : 'Save product') : (inv.saving ? 'Submitting…' : 'Submit for approval') }}
        </button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.adjust" @click.self="modals.adjust=false">
    <div class="modal">
      <div class="modal-head">
        <h3>{{ isSuper ? "⇅ Adjust stock" : "⇅ Request stock adjustment" }} — {{ inv.adjustProd?.name }}</h3>
        <button class="modal-close" @click="modals.adjust=false">✕</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom:12px" class="muted"><template v-if="isSuper">Warehouse: <b>{{ inv.adjustProd?.stock_qty }} {{ inv.adjustProd?.unit }}</b> · Display: <b>{{ inv.adjustProd?.display_qty }} {{ inv.adjustProd?.unit }}</b></template><template v-else>Display stock: <b>{{ inv.adjustProd?.display_qty }} {{ inv.adjustProd?.unit }}</b> <span v-if="!isSuper" style="color:var(--green-700);font-weight:700">— needs super admin approval</span></template></p>
        <div class="f" v-if="isSuper">
          <label>Apply to</label>
          <select v-model="inv.adjustTarget">
            <option value="warehouse">📦 Warehouse (stocks)</option>
            <option value="display">🖥️ Display (naka-display)</option>
          </select>
        </div>
        <div class="f">
          <label>Quantity change (+ add / − deduct) *</label>
          <input type="number" v-model.number="inv.adjustQty" placeholder="e.g. 10 or -3">
          <div class="hint" v-if="inv.adjustProd" style="color:var(--danger)" v-show="(inv.adjustTarget==='warehouse' ? inv.adjustProd.stock_qty : inv.adjustProd.display_qty) + (inv.adjustQty || 0) < 0">⚠️ This would make the {{ inv.adjustTarget }} stock negative.</div>
        </div>
        <div class="f">
          <label>Reason *</label>
          <input v-model.trim="inv.adjustNote" placeholder="e.g. physical count correction, damaged items">
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.adjust=false">Cancel</button>
        <button class="btn btn-primary" @click="saveAdjust" :disabled="inv.saving">{{ isSuper ? (inv.saving ? 'Applying…' : 'Apply adjustment') : (inv.saving ? 'Submitting…' : 'Submit for approval') }}</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.transfer" @click.self="modals.transfer=false">
    <div class="modal">
      <div class="modal-head">
        <h3>{{ isSuper ? "⇄ Transfer stock" : "⇄ Request stock transfer" }} — {{ inv.transferProd?.name }}</h3>
        <button class="modal-close" @click="modals.transfer=false">✕</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom:12px" class="muted">
          Warehouse: <b>{{ inv.transferProd?.stock_qty }} {{ inv.transferProd?.unit }}</b> ·
          {{ t('On display') }}: <b>{{ inv.transferProd?.display_qty }} {{ inv.transferProd?.unit }}</b>
          <span v-if="!isSuper" style="color:var(--green-700);font-weight:700">— needs super admin approval</span>
        </p>
        <div class="f">
          <label>Direction *</label>
          <select v-model="inv.transferDir">
            <option value="to_display">⬇ Warehouse → Display (ilalabas na paninda)</option>
            <option value="to_warehouse">⬆ Display → Warehouse (ibalik sa stocks)</option>
          </select>
        </div>
        <div class="f">
          <label>Quantity *</label>
          <input type="number" min="1" v-model.number="inv.transferQty" placeholder="e.g. 10">
          <div class="hint" v-if="inv.transferProd"
               style="color:var(--danger)"
               v-show="inv.transferDir==='to_display' && (inv.transferProd.stock_qty - (inv.transferQty||0)) < 0">
            ⚠️ Not enough warehouse stock (only {{ inv.transferProd.stock_qty }}).
          </div>
          <div class="hint" v-if="inv.transferProd"
               style="color:var(--danger)"
               v-show="inv.transferDir==='to_warehouse' && (inv.transferProd.display_qty - (inv.transferQty||0)) < 0">
            ⚠️ Not enough display stock (only {{ inv.transferProd.display_qty }}).
          </div>
        </div>
        <div class="f">
          <label>Reason *</label>
          <input v-model.trim="inv.transferNote" placeholder="e.g. replace shelf stock, restock display">
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.transfer=false">Cancel</button>
        <button class="btn btn-primary" @click="saveTransfer" :disabled="inv.saving">{{ isSuper ? (inv.saving ? 'Transferring…' : 'Transfer now') : (inv.saving ? 'Submitting…' : 'Submit for approval') }}</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.cats" @click.self="modals.cats=false">
    <div class="modal">
      <div class="modal-head"><h3>🗂 Categories</h3><button class="modal-close" @click="modals.cats=false">✕</button></div>
      <div class="modal-body">
        <div style="display:flex;gap:8px;margin-bottom:16px">
          <input v-model.trim="inv.newCat" placeholder="New category name" style="flex:1;padding:10px 12px;border:1.6px solid var(--line);border-radius:10px;font-family:inherit">
          <button class="btn btn-primary" @click="saveCategory()">Add</button>
        </div>
        <table class="tbl">
          <thead><tr><th>Name</th><th class="num">Products</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in inv.cats" :key="c.id">
              <td>
                <template v-if="inv.editCatId===c.id">
                  <input v-model.trim="inv.editCatName" style="width:100%;padding:7px 10px;border:1.6px solid var(--green-500);border-radius:8px;font-family:inherit">
                </template>
                <span v-else class="strong">{{ c.name }}</span>
              </td>
              <td class="num">{{ c.product_count }}</td>
              <td style="white-space:nowrap">
                <template v-if="inv.editCatId===c.id">
                  <button class="btn btn-primary btn-sm" @click="saveCategory(c.id)">Save</button>
                  <button class="btn btn-outline btn-sm" @click="inv.editCatId=null">Cancel</button>
                </template>
                <template v-else>
                  <button class="btn btn-outline btn-sm" @click="inv.editCatId=c.id; inv.editCatName=c.name">Rename</button>
                  <button class="btn btn-danger btn-sm" v-if="!c.product_count" @click="deleteCategory(c)">🗑</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.request" @click.self="modals.request=false">
    <div class="modal">
      <div class="modal-head"><h3>📝 New stock request</h3><button class="modal-close" @click="modals.request=false">✕</button></div>
      <div class="modal-body">
        <div class="f">
          <label>Product *</label>
          <select v-model.number="req.form.product_id">
            <option :value="0" disabled>— choose a product —</option>
            <option v-for="p in invProducts" :key="p.id" :value="p.id">
              {{ p.name }} (stock: {{ p.stock_qty }} {{ p.unit }})
            </option>
          </select>
          <div class="hint" v-if="!invProducts.length">Loading products… open Inventory first if this stays empty.</div>
        </div>
        <div class="f">
          <label>Request type *</label>
          <select v-model="req.form.req_type">
            <option value="stock_in">Stock-in (restock delivery)</option>
            <option value="price_change">Price change</option>
            <option value="damage_report">Damage / loss report</option>
          </select>
        </div>
        <div class="f" v-if="req.form.req_type!=='price_change'">
          <label>{{ req.form.req_type==='damage_report' ? 'Damaged / lost quantity *' : 'Quantity to add *' }}</label>
          <input type="number" min="1" v-model.number="req.form.qty">
        </div>
        <div class="f" v-if="req.form.req_type==='price_change'">
          <label>New selling price (₱) *</label>
          <input type="number" min="0" step="0.01" v-model.number="req.form.new_price">
        </div>
        <div class="f">
          <label>Reason / note *</label>
          <textarea v-model.trim="req.form.reason" placeholder="e.g. supplier delivery arrived; items rusted in storage"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.request=false">Cancel</button>
        <button class="btn btn-primary" @click="submitRequest" :disabled="req.saving">{{ req.saving ? 'Submitting…' : 'Submit for approval' }}</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.user" @click.self="modals.user=false">
    <div class="modal">
      <div class="modal-head">
        <h3>{{ users.form.id ? '✏️ Edit user' : '＋ Add user' }}</h3>
        <button class="modal-close" @click="modals.user=false">✕</button>
      </div>
      <div class="modal-body">
        <div class="f-grid">
          <div class="f full"><label>Full name *</label><input v-model.trim="users.form.full_name"></div>
          <div class="f"><label>Username *</label><input v-model.trim="users.form.username" :disabled="!!users.form.id"></div>
          <div class="f">
            <label>Role *</label>
            <select v-model="users.form.role">
              <option value="cashier">Cashier</option>
              <option value="admin">Admin</option>
              <option value="super_admin">Super admin</option>
            </select>
          </div>
          <div class="f full"><label>Phone</label><input v-model.trim="users.form.phone" placeholder="09xx xxx xxxx"></div>
          <div class="f full" v-if="!users.form.id">
            <label>Initial password *</label>
            <input type="password" v-model="users.form.password" placeholder="min 6 characters">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.user=false">Cancel</button>
        <button class="btn btn-primary" @click="saveUser">Save user</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="modals.resetPass" @click.self="modals.resetPass=false">
    <div class="modal">
      <div class="modal-head"><h3>🔑 Reset password — {{ users.resetTarget?.full_name }}</h3><button class="modal-close" @click="modals.resetPass=false">✕</button></div>
      <div class="modal-body">
        <div class="f">
          <label>New password *</label>
          <input type="password" v-model="users.newPass" placeholder="min 6 characters">
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="modals.resetPass=false">Cancel</button>
        <button class="btn btn-primary" @click="doResetPass">Reset</button>
      </div>
    </div>
  </div>

</div>

<script>
window.__errs = [];
window.__diag = { scriptRuns: [] };
window.addEventListener("error", e => window.__errs.push("ERR: " + e.message + " @ " + (e.filename || "").split("/").pop() + ":" + e.lineno));
window.addEventListener("unhandledrejection", e => window.__errs.push("REJ: " + (e.reason && e.reason.message ? e.reason.message : e.reason)));
window.__diag.scriptRuns.push("early-capture");
console.error = function (...args) {
  const first = args.find(a => a instanceof Error) || args[0];
    window.__errs.push("CE: " + args.map(a => String(a && a.message ? a.message : a)).join(" | ").slice(0, 150) + " || " + stack);
};
console.warn = function (...args) {
  window.__errs.push("CW: " + args.map(a => String(a && a.message ? a.message : a)).join(" | ").slice(0, 200));
};
window.addEventListener("error", e => window.__errs.push("ERR: " + e.message + " @ line " + e.lineno));
window.addEventListener("unhandledrejection", e => window.__errs.push("REJ: " + (e.reason && e.reason.message ? e.reason.message : e.reason)));
const __origCE = console.error;
console.error = function (...args) {
  const first = args.find(a => a instanceof Error) || args[0];
    const msg = args.map(a => String(a && a.message ? a.message : a)).join(" | ").slice(0, 200);
  if (!window.__errs.some(x => x.includes(msg.slice(0, 40)))) window.__errs.push("CE: " + msg + " || STACK: " + stack);
  __origCE.apply(console, args);
};
const __origCW = console.warn;
console.warn = function (...args) {
  const msg = args.map(a => String(a && a.message ? a.message : a)).join(" | ").slice(0, 400);
  if (!window.__errs.some(x => x.includes(msg.slice(0, 40)))) window.__errs.push("CW: " + msg);
  __origCW.apply(console, args);
};
</script>
<script src="Assets/vue.global.prod.js?v=2"></script>
<script src="Assets/sweetalert2.min.js?v=2"></script>
<script src="Assets/chart.umd.min.js?v=2"></script>
<script>
const IS_SUPER = <?= $IS_SUPER ? 'true' : 'false' ?>;
const ME = <?= json_encode($me) ?>;

const { createApp, ref, reactive, computed, onMounted, nextTick } = Vue;

Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
Chart.defaults.color = '#52636f';
const GREEN = ['#16a34a', '#15803d', '#22c55e', '#166534', '#4ade80', '#14532d', '#86efac'];

const __app = createApp({
  setup() {
    const me = ME;
    const isSuper = IS_SUPER;

    
    const lang = ref(localStorage.getItem('mh_lang') || 'en');
    function saveLang() { localStorage.setItem('mh_lang', lang.value); }
    const FIL = {
      'Main': 'Pangunahing', 'Workflow': 'Daloy ng Trabaho', 'Insights': 'Mga Insight',
      'Administration': 'Administrasyon', 'Dashboard': 'Dashboard', 'Inventory': 'Imbentaryo',
      'Display': 'Display', 'Stocks (warehouse)': 'Stocks (bodega)',
      'My Requests': 'Mga Kahilingan Ko', 'Approvals': 'Mga Pag-aprubahan',
      'Reports': 'Mga Report', 'History Logs': 'Tala ng Kasaysayan',
      'Users': 'Mga User', 'Backup & Restore': 'Backup at Pagbabalik',
      "Today's Sales": 'Sales Ngayong Araw', 'Transactions Today': 'Mga Transaksyon Ngayon',
      'Low Stock Items': 'Mga Low Stock', 'Pending Approvals': 'Naghihintay na Aprobasyon',
      'My Requests Awaiting Approval': 'Mga Kahilingang Naghihintay',
      'View Sales Reports': 'Tingnan ang Sales Reports',
      'View low stock in Inventory': 'Tingnan ang mababang stock sa Imbentaryo',
      'View Approvals': 'Tingnan ang mga Pag-aprubahan', 'View my requests': 'Tingnan ang mga kahilingan ko',
      'Sales — last 7 days': 'Sales — Huling 7 Araw', 'Revenue & gross profit per day': 'Kita at grosing tubo kada araw',
      'View full reports': 'Tingnan ang buong report', '⚠️ Low stock': '⚠️ Mababang stock',
      'At or below reorder level': 'Nasa o mas mababa sa reorder level',
      'adjust in Inventory': 'ayusin sa Imbentaryo',
      'All stocks healthy': 'Lahat ng stock ay ok',
      'No product is at or below its reorder level.': 'Walang produktong nasa o mas mababa sa reorder level nito.',
      '📦 Inventory Report': '📦 Report ng Imbentaryo',
      'Fast & slow moving products — summary': 'Mabilis at mabagal gumalaw na produkto — buod',
      'View full report': 'Tingnan ang buong report',
      '🔥 Fast moving': '🔥 Mabilis gumalaw', '🐢 Slow moving': '🐢 Mabagal gumalaw',
      '😴 No sales': '😴 Walang benta', 'days': 'araw',
      '📋 All': '📋 Lahat', 'All': 'Lahat', '😴 No sales — top': '😴 Walang benta — top',
      'Labels': 'Mga Label', 'Barcode labels': 'Mga Label ng Barcode', '🔫 Scan to transfer': '🔫 Scan para sa paglipat',
      'Scan box barcode, then press Enter…': 'I-scan ang barcode ng kahon, tapos pindutin ang Enter…',
      'Moves warehouse stock to display directly.': 'Direktang inililipat ang warehouse stock papunta sa display.',
      'Files a transfer request for super admin approval.': 'Nagfa-file ng transfer request para sa aprobrasyon ng super admin.',
      '📥 Receive stock (scan)': '📥 Pagtanggap ng stock (scan)',
      'Scan each box — every scan adds 1 box': 'I-scan ang bawat kahon — bawat scan ay 1 kahon',
      'Confirm below to add the scanned boxes to warehouse stock.': 'Kumpirmahin sa ibaba para maidagdag ang mga na-scan na kahon sa warehouse stock.',
      'Boxes': 'Mga Kahon', 'Units per box': 'Units kada kahon', 'Units to add': 'Mga units na idadagdag',
      'Confirm receive': 'Kumpirmahin ang pagtanggap', 'Clear list': 'Burahin ang listahan', 'Saving…': 'Sinise-save…',
      '💰 Stock value sitting (no sales)': '💰 Halaga ng stock na nakaimbak (walang benta)',
      '🔥 Fast movers — top': '🔥 Pinakamabiling — top', '😴 Sitting stock — top': '😴 Nakaimbak na stock — top',
      'Sold': 'Nabenta', 'Display left': 'Natitira sa display', 'On display': 'Naka-display',
      'Stock value': 'Halaga ng stock', 'All products have sales. 👍': 'Lahat ng produkto ay may benta. 👍',
      'No sales in this period.': 'Walang benta sa panahong ito.',
      '🧾 Latest transactions': '🧾 Pinakabagong transaksyon',
      'Latest sales — click to view the receipt': 'Pinakabagong benta — pindutin para makita ang resibo',
      'View all transactions': 'Tingnan lahat ng transaksyon',
      '🏆 Top products': '🏆 Mga top product',
      'Best sellers right now — analytics': 'Mga pinakamabiling produkto ngayon — analytics',
      'Full analytics': 'Buong analytics',
      'Receipt': 'Resibo', 'Cashier': 'Cashier', 'Total': 'Kabuuan', 'View': 'Tingnan',
      'No sales yet today.': 'Wala pang benta ngayong araw.',
      '🕒 Recent activity': '🕒 Kamakailang aktibidad',
      'Latest actions across the system': 'Mga pinakabagong kilos sa buong sistema',
      'Time': 'Oras', 'User': 'User', 'Action': 'Kilos', 'Details': 'Detalye',
      'Qty': 'Dami', 'Revenue': 'Kita', 'Product': 'Produkto', 'Stock': 'Stock',
      'Slow / no sales': 'Mabagal / walang benta', 'no sales': 'walang benta',
      'Product image (optional — used on POS cards)': 'Larawan ng produkto (opsyonal — para sa POS cards)',
      'If there is no image, the category emoji is used.': 'Kung walang image, emoji ng category ang gagamitin.',
      'Unit — how is the product counted?': 'Unit — paano binibilang ang produkto?',
      'Products on display appear here — use the "+" button to add products.': 'Ang mga products ay naka-display dito — ang pagdaragdag ay nasa "+" button.',
      '💾 Backup Management': '💾 Pamamahala ng Backup',
      'Create, download, and manage backups': 'Gumawa, i-download, at pamahalaan ang mga backup',
      '🤖 Auto-backup now': '🤖 Auto-backup ngayon', 'Creating…': 'Gumagawa…',
      '💾 Create Backup Now': '💾 Gumawa ng Backup Ngayon',
      'File': 'File', 'Size': 'Laki', 'Date & Time': 'Petsa at Oras', 'Type': 'Uri',
      'Status': 'Katayuan', 'Actions': 'Mga Kilos',
      'Verify': 'Suriin', 'Download': 'I-download', 'Restore': 'Ibalik', 'Delete': 'Burahin',
      'successful': 'matagumpay',
      'No backups yet — click "Create Backup Now" above.': 'Wala pang backup — pindutin ang "Gumawa ng Backup Ngayon" sa itaas.',
      '🤖 Automatic Backup Settings': '🤖 Mga Setting ng Awtomatikong Backup',
      'Automatic backup on a set schedule': 'Awtomatikong backup sa nakatakdang schedule',
      'Automatic Backup': 'Awtomatikong Backup', '✅ Enabled': '✅ Naka-on', '⛔ Disabled': '⛔ Naka-off',
      'Frequency': 'Dalas', 'Daily': 'Araw-araw', 'Weekly': 'Linggu-linggo', 'Monthly': 'Buwan-buwan',
      'Retention Period (days)': 'Panahong Panatilihin (araw)',
      'Auto backups older than the set days will be deleted.': 'Buburahin ang mga auto backup na mas lumang sa itinakdang araw.',
      'Backup Time': 'Oras ng Backup', 'Recommended: 10:00 PM — after store hours.': 'Inirerekomenda: 10:00 PM — pagkatapos ng operasyon.',
      '♻️ Restore Management': '♻️ Pamamahala ng Pagbabalik',
      'Restore the database from a backup — a safety backup is created first': 'Ibalik ang database mula sa backup — may safety backup muna',
      '♻️ Restore Database': '♻️ Ibalik ang Database',
      'Restore Steps:': 'Mga Hakbang sa Pag-restore:',
      'Select Backup → Verify Backup → Create Safety Backup → Confirm Restore': 'Pumili ng Backup → Suriin ang Backup → Gumawa ng Safety Backup → Kumpirmahin ang Pag-restore',
      'Restoring an old backup may remove transactions, sales, inventory changes, and user updates made after the backup date.': 'Ang pag-restore ng lumang backup ay maaaring mag-alis ng transactions, sales, inventory changes, at user updates na ginawa pagkatapos ng backup date.',
      '📝 Backup Activity Logs': '📝 Mga Tala ng Aktibidad ng Backup',
      'Action · Performed By · Date & Time · Status · Details': 'Kilos · Gumawa · Petsa at Oras · Katayuan · Detalye',
      'No backup activity yet.': 'Wala pang aktibidad sa backup.',
      '☠️ Danger Zone': '☠️ Delikadong Bahagi',
      'Super Administrator only — multiple confirmations required': 'Super Administrator lamang — maraming kumpirmasyon',
      'Resets the system to its initial state — ALL data is deleted except default accounts.': 'Ibbalik ang system sa initial state — buburahin LAHAT ng data maliban sa default accounts.',
      'THIS WILL ERASE the current data': 'BURAHIN ang kasalukuyang data',
      'and replace it with the contents of this backup file. A safety backup will be created first.': 'at ipapalit ang laman ng backup file na ito. May safety backup na gagawin muna.',
      'THIS DELETES ALL DATA': 'BURAHIN LAHAT NG DATA',
      'Sales, products, inventory, and requests — everything will be erased. Only default accounts will remain.': 'Sales, products, inventory, requests — lahat mabubura. Ang mga default accounts lang ang maiiwan.',
      '🛡️ safety': '🛡️ safety', '🤖 auto backup': '🤖 auto backup', '💾 manual backup': '💾 manual backup',
    };
    function t(s) { return lang.value === 'fil' ? (FIL[s] || s) : s; }
    const langOpen = ref(false);
    function pickLang(v) { lang.value = v; saveLang(); langOpen.value = false; }
    document.addEventListener('click', () => { langOpen.value = false; });
    const view = ref('dashboard');
    const sideOpen = ref(false);
    const userMenuOpen = ref(false);
    const pageTitle = computed(() => ({
      dashboard: 'Dashboard', inventory: 'Inventory', stocksdisplay: 'Stocks & Display', requests: 'My Stock Requests',
      approvals: 'Approvals', reports: 'Reports', audit: 'History Logs', users: 'User Management', backup: 'Backup & Restore',
      invreport: 'Inventory Report',
    }[view.value] || ''));
    const pageCrumb = computed(() => me.full_name + ' · Marina\'s Hardware');

    const initials = computed(() => me.full_name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase());

    
    const fmtP = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtN = n => Number(n || 0).toLocaleString('en-PH');
    const fmtDT = s => s ? new Date(s.replace(' ', 'T')).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    const todayISO = () => new Date().toISOString().slice(0, 10);

    function timeAgo(s) {
      const d = new Date(s.replace(' ', 'T'));
      const sec = Math.floor((Date.now() - d.getTime()) / 1000);
      if (sec < 60) return 'just now';
      if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
      if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
      return Math.floor(sec / 86400) + 'd ago';
    }

    async function api(url, body) {
      const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: body ? { 'Content-Type': 'application/json' } : undefined,
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'same-origin',
      });
      const r = await res.json().catch(() => ({ success: false, message: 'Server error' }));
      if (r.logout) location.href = 'Login.php?expired=1';
      return r;
    }
    const toast = (icon, title) => Swal.fire({ icon, title, toast: true, position: 'top-end', showConfirmButton: false, timer: 2600 });

    const catIcon = c => ({
      'Tools': '🔨', 'Electrical': '💡', 'Plumbing': '🚰', 'Paint & Finishing': '🎨',
      'Building Materials': '🧱', 'Hardware & Fasteners': '🔩', 'Safety & Protection': '⛑️',
    }[c] || '📦');
    const reqTypeLabel = t => ({
      stock_in: '📦 Stock-in', price_change: '🏷 Price change', damage_report: '💥 Damage',
      new_product: '🆕 New product', edit_product: '✏️ Product change', transfer_stock: '⇄ Transfer',
    }[t] || t);
    const notifIcon = t => ({ low_stock: '⚠️', approval: '📝', approved: '✅', rejected: '❌', new_user: '🆕', info: 'ℹ️' }[t] || '🔔');

    
    const FIELD_LABELS = { name: 'Name', barcode: 'Barcode/SKU', category_name: 'Category', unit: 'Unit', cost_price: 'Cost price', selling_price: 'Selling price', reorder_level: 'Reorder level' };
    const NUMERIC_FIELDS = ['cost_price', 'selling_price', 'reorder_level', 'stock_qty'];
    function fieldChanged(k, o, n) {
      if (NUMERIC_FIELDS.includes(k)) return Number(o || 0) !== Number(n || 0);
      return String(o ?? '') !== String(n ?? '');
    }
    function prettyVal(k, v) {
      if (k === 'cost_price' || k === 'selling_price') return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
      return String(v ?? '—');
    }
    function editDiffSummary(pd) {
      if (!pd || !pd.new) return 'product change';
      const diffs = [];
      Object.keys(FIELD_LABELS).forEach(k => {
        const o = pd.old ? pd.old[k] : undefined;
        const n = pd.new[k];
        if (fieldChanged(k, o, n)) diffs.push(FIELD_LABELS[k] + ': ' + prettyVal(k, o) + ' → ' + prettyVal(k, n));
      });
      return diffs.length ? diffs.join(' · ') : 'no field changes';
    }
    function editDiffRows(pd) {
      if (!pd || !pd.new) return [];
      const rows = [];
      Object.keys(FIELD_LABELS).forEach(k => {
        const o = pd.old ? pd.old[k] : undefined;
        const n = pd.new[k];
        if (fieldChanged(k, o, n)) rows.push([FIELD_LABELS[k], prettyVal(k, o), prettyVal(k, n)]);
      });
      return rows;
    }
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* ---------------- notifications ---------------- */
    const notif = reactive({ open: false, unread: 0, list: [] });
    let lastUnread = -1;

    async function loadUnread() {
      const r = await api('api_notifications.php?action=unread_count');
      if (r.success) {
        // when a new notification arrives (e.g. super admin just approved your
        // request), refresh whatever request list is on screen so the status
        // flips to approved/rejected automatically — no F5 needed.
        if (lastUnread !== -1 && r.count !== lastUnread) {
          if (view.value === 'requests') loadRequests();
          if (view.value === 'approvals') loadApprovals();
        }
        lastUnread = r.count;
        notif.unread = r.count;
      }
    }
    async function loadNotifList() {
      const r = await api('api_notifications.php?action=list');
      if (r.success) notif.list = r.notifications;
    }
    function toggleBell() {
      userMenuOpen.value = false;
      notif.open = !notif.open;
      if (notif.open) loadNotifList();
    }
    async function openNotif(n) {
      if (!Number(n.is_read)) {
        await api('api_notifications.php?action=mark_read', { id: n.id });
        n.is_read = 1;
        loadUnread();
      }
    }
    async function markAllRead() {
      await api('api_notifications.php?action=mark_all_read', {});
      notif.list.forEach(n => n.is_read = 1);
      loadUnread();
    }
    async function clearRead() {
      await api('api_notifications.php?action=clear', {});
      loadNotifList(); loadUnread();
    }

    /* ---------------- navigation ---------------- */
    const counts = reactive({ queue: 0, myRequests: 0 });
    function go(v) {
      // the products table belongs to admins — the super admin gets the report instead
      view.value = v;
      sideOpen.value = false;
      window.scrollTo(0, 0);
      sessionStorage.setItem('mh_admin_view', v); // restore this view after F5
      if (v === 'inventory') loadInventory();
      if (v === 'stocksdisplay') loadInventory();
      if (v === 'backup' && isSuper) loadBackups();
      if (v === 'invreport' && isSuper) { loadMovement(); loadInventory(); }
      if (v === 'requests') loadRequests();
      if (v === 'approvals') loadApprovals();
      if (v === 'reports') { loadReport(); if (rep.kind === 'voided') loadVoided(); else if (isSuper && rep.kind === 'inventory') loadMovement(); }
      if (v === 'audit') loadAudit();
      if (v === 'users') loadUsers();
      if (v === 'dashboard') loadDashboard();
    }
    async function loadCounts() {
      // role-aware: super admin approves (queue), admin files (my requests)
      if (isSuper) {
        const q = await api('api_approvals_v2.php?action=queue').catch(() => null);
        if (q && q.success) counts.queue = q.requests.length;
      } else {
        const m = await api('api_approvals_v2.php?action=my_requests').catch(() => null);
        if (m && m.success) counts.myRequests = m.requests.length;
      }
    }

    /* ---------------- dashboard ---------------- */
    const dash = reactive({ todayRevenue: 0, todayTxns: 0, lowStock: 0, lowStockList: [], activity: [], latest: [], topProducts: [] });
    let dashChart = null;

    async function loadDashboard() {
      const d = await api('reports.php?action=summary&period=daily&date=' + todayISO());
      if (d.success) {
        dash.todayRevenue = d.summary.revenue;
        dash.todayTxns = d.summary.transactions;
        dash.latest = d.recent || [];
        dash.topProducts = (d.top_products || []).slice(0, 5);
      }
      const ls = await api('api_inventory_v2.php?action=low_stock');
      if (ls.success) {
        dash.lowStock = ls.products.length;
        dash.lowStockList = ls.products.slice(0, 8);
      }
      const au = await api('api_audit.php?action=list');
      if (au.success) dash.activity = au.logs.slice(0, 8);
      if (isSuper) await loadMovement();   // inventory report sa dashboard

      const from = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);
      const wk = await api('reports.php?action=summary&period=custom&from=' + from + '&to=' + todayISO());
      if (wk.success) {
        await nextTick();
        drawDashChart(wk.series);
      }
    }
    function goToInvReport() {
      rep.kind = 'inventory';
      go('reports');
    }

    function goSalesReport() {
      rep.kind = 'sales';
      go('reports');
    }
    function goLowStock() {
      inv.lowOnly = true;
      go('inventory');
    }
    function drawDashChart(series) {
      const el = document.getElementById('dashChart');
      if (!el) return;
      if (dashChart) dashChart.destroy();
      dashChart = new Chart(el, {
        data: {
          labels: series.map(s => s.label),
          datasets: [
            { type: 'bar', label: 'Revenue', data: series.map(s => s.sales), backgroundColor: '#16a34a', borderRadius: 6, maxBarThickness: 26 },
            { type: 'line', label: 'Profit', data: series.map(s => s.profit), borderColor: '#14532d', backgroundColor: '#14532d', tension: .35, pointRadius: 3 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { labels: { usePointStyle: true } } },
          scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } },
        },
      });
    }

    /* ---------------- inventory ---------------- */
    const inv = reactive({
      allProducts: [], cats: [], search: '', catFilter: 0, sort: 'name_asc', lowOnly: false,
      loading: false, saving: false,
      form: {}, uploading: false, adjustProd: null, adjustQty: null, adjustNote: '', adjustTarget: 'warehouse', transferProd: null, transferDir: 'to_display', transferQty: null, transferNote: '',
      newCat: '', editCatId: null, editCatName: '',
    });
    const modals = reactive({ product: false, adjust: false, transfer: false, cats: false, request: false, user: false, resetPass: false });

    /* INSTANT search / category / sort / low-stock — everything is filtered
       client-side from the full product list, so there is zero server delay */
    const invProducts = computed(() => {
      let list = inv.allProducts;
      const terms = inv.search.toLowerCase().split(/\s+/).filter(Boolean);
      if (terms.length) {
        list = list.filter(p => {
          const hay = (p.name + ' ' + (p.barcode || '')).toLowerCase();
          return terms.every(t => hay.includes(t));
        });
      }
      if (inv.catFilter) list = list.filter(p => Number(p.category_id) === Number(inv.catFilter));
      if (inv.lowOnly) list = list.filter(p => Number(p.display_qty) <= Number(p.reorder_level));
      if (inv.sort === 'stock_asc') list = [...list].sort((a, b) => Number(a.display_qty) - Number(b.display_qty));
      else if (inv.sort === 'stock_desc') list = [...list].sort((a, b) => Number(b.display_qty) - Number(a.display_qty));
      else if (inv.sort === 'price_asc') list = [...list].sort((a, b) => Number(a.selling_price) - Number(b.selling_price));
      else if (inv.sort === 'price_desc') list = [...list].sort((a, b) => Number(b.selling_price) - Number(a.selling_price));
      else list = [...list].sort((a, b) => a.name.localeCompare(b.name));
      return list;
    });

    /* read-only display list for the super admin's Inventory Report page */
    const invDisplayList = computed(() =>
      [...inv.allProducts].sort((a, b) => a.name.localeCompare(b.name))
    );

    async function loadInventory() {
      inv.loading = true;
      const r = await api('api_inventory_v2.php?action=list');
      if (r.success) inv.allProducts = r.products;
      inv.loading = false;
    }
    function clearSearch() { inv.search = ''; }
    async function loadCats() {
      const r = await api('api_inventory_v2.php?action=categories');
      if (r.success) inv.cats = r.categories;
    }
    /* input guards: product name = letters/numbers/space + basic punctuation only;
       numeric fields can never go negative */
    const NAME_BAD = /[^A-Za-z0-9 \-.()\/"ñÑ]/g;
    function onNameInput(e) {
      const cleaned = e.target.value.replace(NAME_BAD, '');
      e.target.value = cleaned;
      inv.form.name = cleaned;
    }
    function onNumInput(e, key) {
      let v = parseFloat(e.target.value);
      if (isNaN(v) || v < 0) { v = 0; e.target.value = 0; }
      inv.form[key] = v;
    }

    
    async function onImagePick(e) {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      inv.uploading = true;
      try {
        const fd = new FormData();
        fd.append('image', file);
        const res = await fetch('api_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const r = await res.json();
        if (r.success) {
          inv.form.image = r.path;
          toast('success', 'Image uploaded.');
        } else {
          toast('error', r.message || 'Upload failed');
          e.target.value = '';
        }
      } catch (err) {
        toast('error', 'Upload failed');
        e.target.value = '';
      } finally {
        inv.uploading = false;
      }
    }

    
    function genSku(catName) {
      const words = (catName || 'General').split(/[^A-Za-z]+/).filter(Boolean);
      const prefix = (words.map(w => w[0]).join('').toUpperCase() || 'GEN').slice(0, 3);
      return 'MH-' + prefix + '-' + String(Math.floor(10000 + Math.random() * 90000));
    }
    function openProduct(p) {
      inv.form = p ? { ...p } : { id: 0, barcode: '', name: '', category_id: inv.cats[0]?.id || 0, unit: 'pc', cost_price: 0, selling_price: 0, stock_qty: 0, reorder_level: 10 };
      if (!p) inv.form.barcode = genSku(inv.cats.find(c => c.id === inv.form.category_id)?.name);
      modals.product = true;
    }
    function regenSku() {
      const cat = inv.cats.find(c => c.id === inv.form.category_id);
      inv.form.barcode = genSku(cat?.name);
    }
    async function saveProduct() {
      if (!inv.form.name || !inv.form.category_id) return toast('warning', 'Name and category are required');
      inv.saving = true;
      const clean = {
        id: inv.form.id || 0,
        name: inv.form.name,
        barcode: inv.form.barcode || '',
        category_id: inv.form.category_id,
        category_name: (inv.cats.find(c => c.id === inv.form.category_id) || {}).name || '',
        unit: inv.form.unit || 'pc',
        cost_price: Math.max(0, Number(inv.form.cost_price) || 0),
        selling_price: Math.max(0, Number(inv.form.selling_price) || 0),
        stock_qty: Math.max(0, Number(inv.form.stock_qty) || 0),
        reorder_level: Math.max(0, Number(inv.form.reorder_level) || 10),
      };
      let r;
      if (isSuper) {
        
        r = await api('api_inventory_v2.php?action=save_product', clean);
      } else {
        
        const reqType = clean.id ? 'edit_product' : 'new_product';
        const payload = reqType === 'edit_product'
          ? { product_id: clean.id, old: inv.allProducts.find(p => p.id === clean.id) || {}, new: clean }
          : clean;
        r = await api('api_approvals_v2.php?action=create', {
          req_type: reqType, payload,
          reason: clean.id ? 'Product update' : 'New product',
        });
      }
      inv.saving = false;
      if (r.success) {
        modals.product = false;
        if (isSuper) {
          toast('success', r.message);
        } else {
          await Swal.fire({
            icon: 'success',
            title: 'Request #' + r.id + ' submitted!',
            text: 'The super admin will review this ' + (clean.id ? 'product change' : 'new product') + '.',
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'View my requests',
          });
          if (view.value !== 'requests') go('requests'); else loadRequests();
          loadCounts();
          loadUnread();
        }
        loadInventory(); loadCats(); loadDashboard();
      } else toast('error', r.message);
    }
    async function archiveProduct(p) {
      const c = await Swal.fire({ icon: 'warning', title: 'Delete ' + p.name + '?', text: 'It will be removed from inventory and POS but sales history stays.', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Yes, delete' });
      if (!c.isConfirmed) return;
      const r = await api('api_inventory_v2.php?action=delete_product', { id: p.id });
      if (r.success) { toast('success', r.message); loadInventory(); loadCats(); loadCounts(); loadDashboard(); }
      else toast('error', r.message);
    }
    function openAdjust(p) { inv.adjustProd = p; inv.adjustQty = null; inv.adjustNote = ''; inv.adjustTarget = 'warehouse'; modals.adjust = true; }
    async function saveAdjust() {
      if (!inv.adjustQty) return toast('warning', 'Enter a quantity (+ to add, − to deduct)');
      if (!inv.adjustNote) return toast('warning', 'Reason is required');
      const cur = inv.adjustTarget === 'display' ? inv.adjustProd.display_qty : inv.adjustProd.stock_qty;
      if (cur + inv.adjustQty < 0) {
        return toast('warning', 'Adjustment would make the ' + inv.adjustTarget + ' stock negative (current: ' + cur + ')');
      }
      inv.saving = true;
      let r;
      if (isSuper) {
        
        r = await api('api_inventory_v2.php?action=adjust_stock', {
          product_id: inv.adjustProd.id, qty: inv.adjustQty, target: inv.adjustTarget, notes: inv.adjustNote,
        });
      } else {
        
        r = await api('api_approvals_v2.php?action=create', {
          req_type: 'adjust_stock',
          payload: { product_id: inv.adjustProd.id, qty: inv.adjustQty, current_stock: inv.adjustProd.stock_qty },
          reason: inv.adjustNote,
        });
      }
      inv.saving = false;
      if (r.success) {
        modals.adjust = false;
        if (isSuper) {
          toast('success', r.message);
        } else {
          await Swal.fire({
            icon: 'success',
            title: 'Request #' + r.id + ' submitted!',
            text: 'The super admin will review this stock adjustment.',
            confirmButtonColor: '#16a34a',
          });
          loadCounts(); loadUnread();
          if (view.value === 'requests') loadRequests();
        }
        loadInventory();
      } else toast('error', r.message);
    }

    
    function openTransfer(p) {
      inv.transferProd = p;
      inv.transferDir = 'to_display';
      inv.transferQty = null;
      inv.transferNote = '';
      modals.transfer = true;
    }
    async function saveTransfer() {
      if (!inv.transferQty || inv.transferQty <= 0) return toast('warning', 'Enter a positive quantity');
      if (!inv.transferNote) return toast('warning', 'Reason is required');
      const src = inv.transferDir === 'to_display' ? inv.transferProd.stock_qty : inv.transferProd.display_qty;
      if (inv.transferQty > src) return toast('warning', 'Not enough stock to transfer (only ' + src + ')');
      inv.saving = true;
      let r;
      if (isSuper) {
        
        r = await api('api_inventory_v2.php?action=transfer_stock', {
          product_id: inv.transferProd.id, direction: inv.transferDir, qty: inv.transferQty, notes: inv.transferNote,
        });
      } else {
        
        r = await api('api_approvals_v2.php?action=create', {
          req_type: 'transfer_stock',
          payload: {
            product_id: inv.transferProd.id, direction: inv.transferDir, qty: inv.transferQty,
            current_stock: inv.transferProd.stock_qty, current_display: inv.transferProd.display_qty,
          },
          reason: inv.transferNote,
        });
      }
      inv.saving = false;
      if (r.success) {
        modals.transfer = false;
        if (isSuper) {
          toast('success', r.message);
        } else {
          await Swal.fire({
            icon: 'success',
            title: 'Request #' + r.id + ' submitted!',
            text: 'The super admin will review this stock transfer.',
            confirmButtonColor: '#16a34a',
          });
          loadCounts(); loadUnread();
          if (view.value === 'requests') loadRequests();
        }
        loadInventory();
      } else toast('error', r.message);
    }
    function openCategories() { modals.cats = true; }
    async function saveCategory(id) {
      const name = id ? inv.editCatName : inv.newCat;
      if (!name) return;
      const r = await api('api_inventory_v2.php?action=save_category', { id: id || 0, name });
      if (r.success) {
        toast('success', r.message);
        inv.newCat = ''; inv.editCatId = null;
        loadCats();
      } else toast('error', r.message);
    }
    async function deleteCategory(c) {
      const r = await api('api_inventory_v2.php?action=delete_category', { id: c.id });
      if (r.success) { toast('success', r.message); loadCats(); }
      else toast('error', r.message);
    }

    
    const movement = reactive({ loading: false, days: 30, summary: null, stats: [], fastList: [], slowList: [] });
    const dashMv = ref('all');                
    const movementTab = ref('graph');           
    const invNavOpen = ref(true);               
    const mvFilter = ref('all');               
    const mvSearch = ref('');
    
    const movementTableRows = computed(() => {
      let list = movement.stats;
      if (mvFilter.value !== 'all') list = list.filter(p => p.movement === mvFilter.value);
      const t = mvSearch.value.toLowerCase().trim();
      if (t) list = list.filter(p => (p.name + ' ' + (p.barcode || '')).toLowerCase().includes(t));
      return list;
    });
    
    const dashSitRows = computed(() => dashMv.value === 'dead' ? movement.slowList.filter(p => p.movement === 'dead') : movement.slowList);

    
    const scanBox = ref('');
    function findProductByBarcode(code) {
      const c = String(code || '').trim().toLowerCase();
      if (!c) return null;
      return inv.allProducts.find(p => String(p.barcode || '').trim().toLowerCase() === c) || null;
    }
    async function scanBoxEnter() {
      const code = scanBox.value;
      if (!code) return;
      let p = findProductByBarcode(code);
      if (!p) { await loadInventory(); p = findProductByBarcode(code); }
      if (!p) return toast('error', 'No product matches barcode "' + code + '"');
      scanBox.value = '';
      openTransfer(p);
    }
    function printLabels(p) {
      window.open('barcode_labels.php', '_blank');
    }
    const rcv = reactive({ scan: '', items: [], busy: false });
    async function rcvScanEnter() {
      const code = rcv.scan;
      if (!code) return;
      let p = findProductByBarcode(code);
      if (!p) { await loadInventory(); p = findProductByBarcode(code); }
      if (!p) return toast('error', 'No product matches barcode "' + code + '"');
      const found = rcv.items.find(x => x.id === p.id);
      if (found) found.scans++;
      else rcv.items.push({ id: p.id, name: p.name, unit: p.unit, scans: 1, per_box: 1 });
      rcv.scan = '';
    }
    function rcvRemove(id) {
      rcv.items = rcv.items.filter(x => x.id !== id);
    }
    async function confirmReceive() {
      const items = rcv.items
        .map(x => ({ product_id: x.id, qty: x.scans * Math.max(1, x.per_box || 1) }))
        .filter(x => x.qty > 0);
      if (!items.length) return toast('warning', 'Nothing to receive');
      rcv.busy = true;
      const r = await api('api_inventory_v2.php?action=receive_stock', { items });
      rcv.busy = false;
      if (r.success) {
        toast('success', r.message);
        rcv.items = [];
        loadInventory();
      } else toast('error', r.message);
    }
    let movementChart = null;

    function drawMovementChart() {
      const el = document.getElementById('movementChart');
      if (!el) return;
      const top = [...movement.stats].sort((a, b) => b.sold30 - a.sold30).slice(0, 12);
      if (movementChart) movementChart.destroy();
      movementChart = new Chart(el, {
        type: 'bar',
        data: {
          labels: top.map(p => p.name.length > 22 ? p.name.slice(0, 21) + '…' : p.name),
          datasets: [{
            label: 'Units sold (' + movement.days + 'd)',
            data: top.map(p => p.sold30),
            backgroundColor: top.map(p => p.movement === 'fast' ? '#16a34a' : p.movement === 'slow' ? '#f59e0b' : '#cbd5e1'),
            borderRadius: 6,
            maxBarThickness: 26,
          }],
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        },
      });
    }
    function setMovementTab(t) {
      movementTab.value = t;
      if (t === 'graph') nextTick(drawMovementChart);
    }

    
    function onReportKind() {
      if (rep.kind === 'inventory') {
        loadMovement();
        nextTick(drawMovementChart);
      } else if (rep.kind === 'voided') {
        loadVoided();
      } else {
        loadReport();
      }
    }

    async function loadMovement() {
      if (!isSuper) return;
      movement.loading = true;
      const r = await api('api_inventory_v2.php?action=movement&days=' + movement.days);
      if (r.success) {
        movement.summary = r.summary;
        movement.stats = r.stats;
        movement.fastList = r.stats.filter(s => s.movement === 'fast').slice(0, 8);
        movement.slowList = r.stats.filter(s => s.movement !== 'fast')
          .sort((a, b) => b.stock_value - a.stock_value).slice(0, 8);
        if (movementTab.value === 'graph') nextTick(drawMovementChart);
      }
      movement.loading = false;
    }

    
    const req = reactive({ my: [], history: [], form: {}, saving: false });
    async function loadRequests() {
      const r = await api('api_approvals_v2.php?action=my_requests');
      if (r.success) req.my = r.requests;
      const h = await api('api_approvals_v2.php?action=history');
      if (h.success) req.history = h.requests;
    }
    function openRequest(productId) {
      if (isSuper) return toast('info', 'Requests are filed by admins — approvals are yours');
      if (!inv.allProducts.length) loadInventory();
      req.form = { product_id: productId || 0, req_type: 'stock_in', qty: 1, new_price: null, reason: '' };
      modals.request = true;
    }
    async function submitRequest() {
      req.saving = true;
      const r = await api('api_approvals_v2.php?action=create', req.form);
      req.saving = false;
      if (r.success) {
        modals.request = false;
        
        await Swal.fire({
          icon: 'success',
          title: 'Request #' + r.id + ' submitted!',
          text: "Waiting for the super admin's approval — you'll get a notification once it's decided.",
          confirmButtonColor: '#16a34a',
          confirmButtonText: 'View my requests',
        });
        if (view.value !== 'requests') go('requests'); else loadRequests();
        loadCounts();
        loadUnread();
      } else toast('error', r.message);
    }

    
    const appr = reactive({ queue: [], history: [] });
    const reqDetail = reactive({ open: false, r: null });
    function viewRequest(r) {
      reqDetail.r = r;
      reqDetail.open = true;
    }
    async function loadApprovals() {
      const q = await api('api_approvals_v2.php?action=queue');
      if (q.success) { appr.queue = q.requests; counts.queue = q.requests.length; }
      const h = await api('api_approvals_v2.php?action=history');
      if (h.success) appr.history = h.requests;
    }
    async function decide(r, decision) {
      const word = decision === 'approved' ? 'Approve' : 'Reject';
      let html;
      if (r.req_type === 'new_product') {
        const pd = r.payload_data || {};
        html = '<div style="text-align:left;font-size:13.5px;line-height:1.9">'
          + '<b>' + esc(pd.name) + '</b><br>'
          + 'Category: ' + esc(pd.category_name) + '<br>'
          + 'Selling price: <b>' + fmtP(pd.selling_price) + '</b> (cost ' + fmtP(pd.cost_price) + ')<br>'
          + 'Initial stock: <b>' + pd.stock_qty + ' ' + esc(pd.unit) + '</b> · reorder at ' + pd.reorder_level
          + '</div>';
      } else if (r.req_type === 'edit_product') {
        const rows = editDiffRows(r.payload_data)
          .map(([k, o, n]) => '<tr><td style="padding:3px 8px;color:#52636f">' + esc(k) + '</td>'
            + '<td style="padding:3px 8px;text-decoration:line-through;color:#dc2626">' + esc(o) + '</td>'
            + '<td style="padding:3px 8px;font-weight:800;color:#15803d">' + esc(n) + '</td></tr>').join('');
        html = '<div style="text-align:left;font-size:13.5px"><table style="width:100%;border-collapse:collapse">'
          + '<tr><th style="text-align:left;padding:3px 8px">Field</th><th style="text-align:left;padding:3px 8px">Current</th><th style="text-align:left;padding:3px 8px">Requested</th></tr>'
          + rows + '</table></div>';
      } else {
        html = '<b>' + esc(r.product_name) + '</b><br>'
          + (r.req_type === 'price_change'
            ? 'Price: ' + fmtP(r.selling_price) + ' → ' + fmtP(r.new_price)
            : 'Quantity: ' + r.qty);
      }
      const res = await Swal.fire({
        title: word + ' request #' + r.id + '?',
        html,
        input: 'textarea',
        inputPlaceholder: decision === 'rejected'
          ? 'Required: reason for rejection…'
          : 'Optional note for ' + r.requested_by_name + '…',
        inputValidator: (v) => {
          if (decision === 'rejected' && !(v || '').trim()) return 'A reason is required when rejecting a request.';
          return null;
        },
        showCancelButton: true,
        confirmButtonColor: decision === 'approved' ? '#16a34a' : '#dc2626',
        confirmButtonText: word,
      });
      if (!res.isConfirmed) return;
      const out = await api('api_approvals_v2.php?action=decide', { id: r.id, decision, note: res.value || '' });
      if (out.success) {
        toast('success', out.message);
        loadApprovals(); loadCounts(); loadUnread(); loadDashboard();
      } else toast('error', out.message);
    }

    
    const rep = reactive({
      kind: 'sales',
      period: 'daily', date: todayISO(), month: todayISO().slice(0, 7),
      from: new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10), to: todayISO(),
      data: null,
      voidedSummary: { count: 0, amount: 0 }, voidedList: [], voidedLabel: '',
    });
    let repChart = null;

    function setPeriod(p) { rep.period = p; if (rep.kind === 'voided') loadVoided(); else loadReport(); }

    function reportQS() {
      const p = new URLSearchParams({ action: 'summary', period: rep.period });
      if (rep.period === 'daily' || rep.period === 'weekly') p.set('date', rep.date);
      if (rep.period === 'monthly') p.set('month', rep.month);
      if (rep.period === 'custom') { p.set('from', rep.from); p.set('to', rep.to); }
      return p.toString();
    }

    async function loadReport() {
      const r = await api('reports.php?' + reportQS());
      if (!r.success) { toast('error', r.message); return; }
      rep.data = r;
      await nextTick();
      drawRepChart(r.series);
    }
    async function loadVoided() {
      const qs = reportQS().replace('action=summary', 'action=voided');
      const r = await api('reports.php?' + qs);
      if (!r.success) { toast('error', r.message); return; }
      rep.voidedSummary = r.summary;
      rep.voidedList = r.voided;
      rep.voidedLabel = r.label;
    }
    function drawRepChart(series) {
      const el = document.getElementById('repChart');
      if (!el) return;
      if (repChart) repChart.destroy();
      repChart = new Chart(el, {
        data: {
          labels: series.map(s => s.label),
          datasets: [
            { type: 'bar', label: 'Revenue (₱)', data: series.map(s => s.sales), backgroundColor: '#16a34a', borderRadius: 6, maxBarThickness: 34 },
            { type: 'line', label: 'Gross profit (₱)', data: series.map(s => s.profit), borderColor: '#14532d', backgroundColor: '#14532d', tension: .3, pointRadius: 2.5, yAxisID: 'y' },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { labels: { usePointStyle: true } } },
          scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } },
        },
      });
    }
    function exportCSV() { location.href = 'reports.php?' + reportQS().replace('action=summary', 'action=export'); }

    
    const rcptDetail = reactive({ open: false, url: '', receiptNo: '', saleId: 0 });
    function viewReceiptAdmin(s) {
      rcptDetail.saleId = s.id;
      rcptDetail.receiptNo = s.receipt_no;
      rcptDetail.url = 'api_print.php?sale_id=' + s.id + '&preview=1';
      rcptDetail.open = true;
    }
    function printReport() { window.print(); }

    
    
    const backup = reactive({ creating: false, loading: false, busy: false, list: [], history: [], restoreHistory: [], restoreFile: null, restoreConfirm: '', overview: {}, settings: {} });
    const bk = backup; 
    function fmtSize(b) {
      if (b > 1048576) return (b / 1048576).toFixed(2) + ' MB';
      if (b > 1024) return (b / 1024).toFixed(1) + ' KB';
      return b + ' B';
    }
    async function loadBackups() {
      if (!isSuper) return;
      backup.loading = true;
      await api('api_backup.php?action=auto_check');   
      const r = await api('api_backup.php?action=list');
      if (r.success) {
        backup.list = r.backups;
        backup.restoreHistory = (r.history || []).filter(h => h.action === 'restore' || h.action === 'safety');
        backup.history = r.history || [];
      }
      const ov = await api('api_backup.php?action=overview');
      if (ov.success) backup.overview = ov.overview;
      const st = await api('api_backup.php?action=auto_settings');
      if (st.success) backup.settings = st.settings;
      backup.loading = false;
    }
    async function runAutoNow() {
      backup.busy = true;
      const r = await api('api_backup.php?action=auto_check');
      backup.busy = false;
      toast(r.created ? 'success' : 'info', r.message);
      loadBackups();
    }
    function backupActionLabel(file) {
      if (file.includes('safety')) return t('🛡️ safety');
      if (file.includes('auto')) return t('🤖 auto backup');
      return t('💾 manual backup');
    }
    function onRestoreFilePick(e) {
      backup.restoreFile = e.target.files && e.target.files[0] ? e.target.files[0] : null;
    }
    function restoreUpload() {
      if (!backup.restoreFile) return toast('warning', 'Choose a .sql backup file first');
      if (backup.restoreConfirm !== 'RESTORE') return toast('warning', 'Type RESTORE to confirm');
      const fd = new FormData();
      fd.append('action', 'restore');
      fd.append('sqlfile', backup.restoreFile);
      fd.append('confirm', 'RESTORE');
      backup.busy = true;
      fetch('api_backup.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(res => res.json())
        .then(r => {
          backup.busy = false;
          if (r.success) {
            Swal.fire({ icon: 'success', title: 'Restored!', text: r.message, confirmButtonColor: '#16a34a' })
              .then(() => location.reload());
          } else toast('error', r.message);
        })
        .catch(() => { backup.busy = false; toast('error', 'Restore failed'); });
    }
    async function createBackup() {
      backup.creating = true;
      const r = await api('api_backup.php?action=create');
      backup.creating = false;
      if (r.success) {
        toast('success', r.message);
        loadBackups();
        window.open('api_backup.php?action=download&file=' + encodeURIComponent(r.file), '_blank');
      } else toast('error', r.message);
    }
    function downloadBackup(f) {
      window.open('api_backup.php?action=download&file=' + encodeURIComponent(f), '_blank');
    }
    async function restoreBackup(f) {
      const res = await Swal.fire({
        title: '♻️ Restore ' + f + '?',
        html: '<div style="font-size:13.5px;color:#dc2626;font-weight:700">' + t('THIS WILL ERASE the current data') + '</div><div style="font-size:13px;color:#52636f">' + t('and replace it with the contents of this backup file. A safety backup will be created first.') + '</div>',
        input: 'text',
        inputPlaceholder: 'Type RESTORE to confirm',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        confirmButtonText: '♻️ Restore now',
        reverseButtons: true,
      });
      if (!res.isConfirmed) return;
      if ((res.value || '').trim() !== 'RESTORE') return toast('warning', 'You must type RESTORE exactly');
      const r = await api('api_backup.php?action=restore_server', { file: f, confirm: (res.value || '').trim() });
      if (r.success) {
        await Swal.fire({ icon: 'success', title: 'Restored!', text: r.message + ' The page will reload.', confirmButtonColor: '#16a34a' });
        location.reload();
      } else toast('error', r.message);
    }
    async function deleteBackup(f) {
      const res = await Swal.fire({
        title: 'Delete backup?',
        text: f,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: '🗑️ Delete',
        reverseButtons: true,
      });
      if (!res.isConfirmed) return;
      const r = await api('api_backup.php?action=delete', { file: f });
      if (r.success) { toast('success', r.message); loadBackups(); }
      else toast('error', r.message);
    }

    async function loadOverview() {
      if (!isSuper) return;
      const r = await api('api_backup.php?action=overview');
      if (r.success) bk_set('overview', r.overview);
    }
    function bk_set(key, val) { backup[key] = val; }
    async function loadAutoSettings() {
      if (!isSuper) return;
      const r = await api('api_backup.php?action=auto_settings');
      if (r.success) bk_set('settings', r.settings);
    }
    async function saveAutoSettings() {
      const r = await api('api_backup.php?action=auto_settings', {
        enabled: !!backup.settings.enabled,
        frequency: backup.settings.frequency,
        time: backup.settings.time,
        retention_days: backup.settings.retention_days,
      });
      if (r.success) toast('success', r.message);
      else toast('error', r.message);
    }
    async function verifyBackup(file) {
      const r = await api('api_backup.php?action=verify', { file });
      if (r.success) {
        Swal.fire({
          icon: r.valid ? 'success' : 'warning',
          title: r.valid ? '✅ Backup is valid' : '⚠️ Backup issue',
          html: '<div style="text-align:left;font-size:13px">' +
            Object.entries(r.checks).map(([k, v]) => '<b>' + k + ':</b> ' + v).join('<br>') + '</div>',
          confirmButtonColor: '#16a34a',
        });
      } else toast('error', r.message);
    }
    async function factoryReset() {
      const pass = await Swal.fire({
        title: '☠️ Factory Reset',
        html: '<div style="font-size:13.5px;color:#dc2626;font-weight:700">' + t('THIS DELETES ALL DATA') + '</div><div style="font-size:13px;color:#52636f">' + t('Sales, products, inventory, and requests — everything will be erased. Only default accounts will remain.') + '</div>',
        input: 'password',
        inputPlaceholder: 'Super admin password',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: '☠️ Continue',
        reverseButtons: true,
      });
      if (!pass.isConfirmed) return;
      const confirm2 = await Swal.fire({
        title: 'Are you SURE?',
        text: 'This action cannot be undone. All sales, products, and inventory data will be permanently deleted.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: '☠️ YES, Factory Reset',
        reverseButtons: true,
      });
      if (!confirm2.isConfirmed) return;
      const r = await api('api_backup.php?action=factory_reset', { password: pass.value || '' });
      if (r.success) {
        await Swal.fire({ icon: 'success', title: 'Factory Reset Complete!', text: 'The system has been reset.', confirmButtonColor: '#16a34a' });
        location.href = 'Login.php';
      } else toast('error', r.message);
    }
    async function restoreUpload() {
      if (!backup.restoreFile) return toast('warning', 'Choose a .sql backup file first');
      if (backup.restoreConfirm !== 'RESTORE') return toast('warning', 'Type RESTORE to confirm');
      const fd = new FormData();
      fd.append('action', 'restore');
      fd.append('sqlfile', backup.restoreFile);
      fd.append('confirm', 'RESTORE');
      backup.busy = true;
      fetch('api_backup.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(res => res.json())
        .then(r => {
          backup.busy = false;
          if (r.success) {
            Swal.fire({ icon: 'success', title: 'Restored!', text: r.message, confirmButtonColor: '#16a34a' })
              .then(() => location.reload());
          } else toast('error', r.message);
        })
        .catch(() => { backup.busy = false; toast('error', 'Restore failed'); });
    }

    const audit = reactive({ logs: [], search: '', loading: false });
    async function loadAudit() {
      audit.loading = true;
      const r = await api('api_audit.php?action=list&search=' + encodeURIComponent(audit.search));
      if (r.success) audit.logs = r.logs;
      audit.loading = false;
    }

    
    const users = reactive({ list: [], loading: false, form: {}, resetTarget: null, newPass: '' });
    async function loadUsers() {
      if (!isSuper) return;
      users.loading = true;
      const r = await api('api_users.php?action=list');
      if (r.success) users.list = r.users;
      users.loading = false;
    }
    function openUser(u) {
      users.form = u ? { id: u.id, full_name: u.full_name, username: u.username, role: u.role, phone: u.phone || '', password: '' }
                     : { id: 0, full_name: '', username: '', role: 'cashier', phone: '', password: '' };
      modals.user = true;
    }
    async function saveUser() {
      const r = await api('api_users.php?action=' + (users.form.id ? 'update' : 'create'), users.form);
      if (r.success) { modals.user = false; toast('success', r.message); loadUsers(); }
      else toast('error', r.message);
    }
    async function toggleUserStatus(u) {
      const to = u.status === 'active' ? 'inactive' : 'active';
      const r = await api('api_users.php?action=set_status', { id: u.id, status: to });
      if (r.success) { toast('success', r.message); loadUsers(); loadUnread(); }
      else toast('error', r.message);
    }
    function openResetPass(u) { users.resetTarget = u; users.newPass = ''; modals.resetPass = true; }
    async function doResetPass() {
      const r = await api('api_users.php?action=reset_password', { id: users.resetTarget.id, password: users.newPass });
      if (r.success) { modals.resetPass = false; toast('success', r.message); }
      else toast('error', r.message);
    }

    
    async function logout() {
      await api('auth.php?action=logout', {});
      location.href = 'Login.php';
    }

    
    onMounted(() => {
      const bootLoader = document.getElementById('boot-loader');
      if (bootLoader) bootLoader.remove();
      
      
      /* Both panels always open on the Dashboard on login and F5 */
      sessionStorage.setItem('mh_admin_view', 'dashboard');
      go('dashboard');
      loadCats();
      loadUnread();
      loadCounts();
      setInterval(loadUnread, 30000);
      
      setInterval(() => { if (view.value === 'inventory' || view.value === 'stocksdisplay') loadInventory(); }, 30000);
      
      setInterval(() => { if (view.value === 'reports' && isSuper && rep.kind === 'inventory') loadMovement(); }, 30000);
      
      setInterval(() => {
        if (view.value === 'approvals') loadApprovals();
        if (view.value === 'requests') loadRequests();
      }, 30000);
      setInterval(() => { if (view.value === 'approvals' || view.value === 'dashboard') loadCounts(); }, 60000);
      document.addEventListener('click', () => { notif.open = false; userMenuOpen.value = false; });
    });

    return {
      me, isSuper, view, sideOpen, userMenuOpen, pageTitle, pageCrumb, initials,
      notif, toggleBell, openNotif, markAllRead, clearRead, notifIcon, timeAgo,
      counts, go, goToInvReport, goSalesReport, goLowStock, invNavOpen,
      dash,
      inv, invProducts, modals, openProduct, regenSku, saveProduct, archiveProduct, openAdjust, saveAdjust, onNameInput, onNumInput, onImagePick,
      openTransfer, saveTransfer,
      movement, loadMovement, movementTab, setMovementTab, dashMv, dashSitRows, scanBox, scanBoxEnter, printLabels, rcv, rcvScanEnter, rcvRemove, confirmReceive, invDisplayList, mvFilter, mvSearch, movementTableRows,
      openCategories, saveCategory, deleteCategory,
      req, loadRequests, openRequest, submitRequest, reqTypeLabel, editDiffSummary, catIcon,
      appr, loadApprovals, decide, reqDetail, viewRequest,
      rep, setPeriod, onReportKind, loadReport, loadVoided, exportCSV, printReport,
      rcptDetail, viewReceiptAdmin,
      lang, saveLang, t, langOpen, pickLang, audit, loadAudit, backup, bk, loadBackups, createBackup, downloadBackup, restoreBackup, deleteBackup, fmtSize, runAutoNow, onRestoreFilePick, restoreUpload, backupActionLabel, loadOverview, loadAutoSettings, saveAutoSettings, verifyBackup, factoryReset,
      users, loadUsers, openUser, saveUser, toggleUserStatus, openResetPass, doResetPass,
      logout,
      fmtP, fmtN, fmtDT,
    };
  },
});
__app.config.errorHandler = (err, inst, info) => {
  const msg = (err && err.message) ? err.message : String(err);
  window.__errs && window.__errs.push("VUE-ERR: " + msg + " | " + info);
};
__app.config.warnHandler = (msg, inst, trace) => {
  window.__errs && window.__errs.push("VUE-WARN: " + String(msg).slice(0, 200) + " | " + String(trace).slice(0, 200));
};
__app.mount('#app');

</script>
</body>
</html>
