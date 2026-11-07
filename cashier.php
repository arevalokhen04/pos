<?php

require __DIR__ . '/db.php';
$user = current_user();
if (!$user) {
    header('Location: Login.php' . (empty($GLOBALS['mh_idle_expired']) ? '' : '?expired=1'));
    exit;
}

if (!in_array($user['role'], ['cashier', 'super_admin'], true)) {
    header('Location: admin.php');
    exit;
}
$me = ['id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cashier POS — Marina's Hardware</title>
<link rel="stylesheet" href="Assets/sweetalert2.min.css?v=2">
<link rel="stylesheet" href="Assets/cashier.css?v=12">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
</head>
<body>
<div id="app" v-cloak>
  <div class="pos">

    
    <header class="pos-top">
      <div class="brand">
        <div class="logo-badge">🔧</div>
        <div>
          <div class="b-name">MARINA'S HARDWARE</div>
          <div class="b-sub">POINT OF SALE</div>
        </div>
      </div>
      <div class="spacer"></div>
      <a v-if="me.role === 'super_admin'" href="super_admin.php" class="btn btn-outline" style="background:rgba(255,255,255,.13);color:#fff;border-color:rgba(255,255,255,.3);text-decoration:none">{{ t('⬅ Admin panel') }}</a>

      <div class="session-chip" :class="{on: session.open}">
        <span class="dot"></span>
        <template v-if="session.open">
          {{ t('Clocked in since') }} {{ fmtT(session.open.time_in) }}
        </template>
        <template v-else>{{ t('Clocked out') }}</template>
      </div>
      <button class="btn btn-outline btn-sm" style="background:rgba(255,255,255,.13);color:#fff;border-color:rgba(255,255,255,.3)"
              @click="session.open ? clockOut() : (clockModal=true)">
        {{ session.open ? t('⏸ Clock out') : t('▶ Clock in') }}
      </button>
      <button class="btn btn-outline btn-sm" style="background:rgba(255,255,255,.13);color:#fff;border-color:rgba(255,255,255,.3)"
              @click="openHistory" :title="t('Receipt history (reprint)')">
        {{ t('🧾 History') }}
      </button>

      
      <div class="lang-wrap" :class="{open: langOpen}" @click.stop="langOpen=!langOpen" title="Language / Wika">
        <span class="lang-globe">🌐</span>
        <span class="lang-cur">{{ lang === 'fil' ? 'Filipino' : 'English' }}</span>
        <span class="lang-chev">▾</span>
        <div class="lang-menu" v-if="langOpen" @click.stop>
          <button type="button" class="lang-opt" :class="{on: lang==='en'}" @click="pickLang('en')">English <span class="chk" v-if="lang==='en'">✓</span></button>
          <button type="button" class="lang-opt" :class="{on: lang==='fil'}" @click="pickLang('fil')">Filipino <span class="chk" v-if="lang==='fil'">✓</span></button>
        </div>
      </div>

      
      <div class="pos-bell">
        <button class="bell-btn2" @click.stop="toggleBell">🔔
          <span class="badge" v-if="unread>0">{{ unread>99?'99+':unread }}</span>
        </button>
        <div class="bell-menu" v-if="bellOpen" @click.stop>
          <div class="bm-head">
            <span>{{ t('Notifications') }}</span>
            <button @click="markAllRead" v-if="unread">{{ t('Mark all read') }}</button>
          </div>
          <div class="bm-list">
            <div class="bm-empty" v-if="!notifs.length"><div class="big">🔕</div>{{ t('No notifications yet.') }}</div>
            <div class="notif-item" v-for="n in notifs" :key="n.id" :class="{unread:!Number(n.is_read)}" @click="readNotif(n)">
              <div class="notif-ico">{{ n.type==='approved' ? '✅' : n.type==='rejected' ? '❌' : n.type==='approval' ? '📝' : n.type==='low_stock' ? '⚠️' : '🔔' }}</div>
              <div style="min-width:0">
                <div class="n-title">{{ n.title }}</div>
                <div class="n-msg">{{ n.message }}</div>
                <div class="n-time">{{ timeAgo(n.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      
      <div class="pos-user" @click.stop="userOpen=!userOpen">
        <div class="avatar">{{ initials }}</div>
        <div>
          <div class="u-name">{{ me.full_name }}</div>
          <div class="u-role">{{ me.role === 'super_admin' ? 'Super Admin' : 'Cashier' }}</div>
        </div>
        <div class="user-menu" v-if="userOpen" @click.stop>
          <button class="danger" @click="logout">{{ t('⏻ Sign out') }}</button>
        </div>
      </div>
    </header>

    
    <div class="pos-body">

      
      <div class="lock-overlay" v-if="!session.open">
        <div class="lock-card">
          <div class="big">🔒</div>
          <h3>{{ t('Clock in to start selling') }}</h3>
          <p>{{ t('You need an active shift before you can select products, add to cart and check out.') }}</p>
          <button class="btn btn-primary" style="padding:13px 34px;font-size:15px" @click="clockModal=true">{{ t('Clock in now') }}</button>
        </div>
      </div>

      
      <section class="prod-side">
        <div class="scan-row">
          <div class="scan-box">
            <span class="ico">🔍</span>
            <input v-model.trim="search" @keydown.enter="scanEnter" ref="scanInput" autocomplete="off" spellcheck="false"
                   :placeholder="t('Scan barcode or search product, then press Enter…')">
          </div>
          <button class="btn btn-outline cart-toggle" @click="cartOpen=!cartOpen">
            🛒 {{ t('Cart') }}<span v-if="cartCount"> ({{ cartCount }})</span>
          </button>
        </div>

        <div class="cat-pills">
          <button class="cat-pill" :class="{active: cat===0}" @click="cat=0; loadProducts()">{{ t('All') }}</button>
          <button class="cat-pill" v-for="c in cats" :key="c.id" :class="{active: cat===c.id}" @click="cat=c.id; loadProducts()">
            {{ c.name }}
          </button>
        </div>

        <div class="prod-grid">
          <template v-if="loading">
            <div class="skel-card" v-for="i in 10" :key="'skc'+i" style="grid-column:auto">
              <div class="skel" style="width:52px;height:52px;border-radius:12px"></div>
              <div class="skel" style="height:12px;width:78%"></div>
              <div class="skel" style="height:11px;width:52%"></div>
            </div>
          </template>
          <div class="bm-empty" style="grid-column:1/-1" v-else-if="!filtered.length">
            <div class="big">🔎</div>{{ t('No matching product.') }}
          </div>
          <div v-for="p in filtered" :key="p.id"
               class="prod-card" :class="{out: avail(p)<=0}"
               @click="addToCart(p)">
            <span class="lowflag" v-if="avail(p) <= p.reorder_level">LOW</span>
            <div class="prod-ico">
              <img v-if="p.image" :src="p.image" loading="lazy" style="width:34px;height:34px;object-fit:cover;border-radius:8px;display:block">
              <template v-else>{{ catIcon(p.category_name) }}</template>
            </div>
            <div class="prod-name">{{ p.name }}</div>
            <div class="prod-meta">
              <div class="prod-price">{{ fmtP(p.selling_price) }}</div>
              <div class="prod-stock" :class="{low: avail(p) <= p.reorder_level}">
                {{ avail(p) }} {{ p.unit }}
              </div>
            </div>
          </div>
        </div>
      </section>

      
      <aside class="cart-side" :class="{open: cartOpen}">
        <div class="cart-head">
          <div><h3>{{ t('🛒 Current sale') }}</h3><div class="cnt">{{ cartCount }} {{ cartCount===1 ? t('item') : t('items') }}</div></div>
          <button class="cart-clear" v-if="cart.length" @click="clearCart">{{ t('Clear all') }}</button>
        </div>

        <div class="cart-items">
          <div class="cart-head-row" v-if="cart.length">
            <span></span>
            <span>{{ t('Name of product') }}</span>
            <span class="h-price">{{ t('Price') }}</span>
            <span class="h-qty">{{ t('Quantity') }}</span>
          </div>
          <div class="cart-empty" v-if="!cart.length">
            <div>
              <div class="big">🛒</div>
              {{ t('Cart is empty.') }}<br>{{ t('Scan or click a product to start.') }}
            </div>
          </div>
          <div class="cart-row" v-for="(it,i) in cart" :key="it.id">
            <button class="rm" @click="qty(i,-1)" title="Bawasan / alisin sa cart" aria-label="Remove item">✕</button>
            <div class="c-name">
              <div class="c-n1">{{ it.name }}</div>
              <div class="c-n2">{{ fmtP(it.price) }} / {{ it.unit }}</div>
            </div>
            <div class="c-amt">{{ fmtP(it.price * it.qty) }}</div>
            <div class="qty-ctl">
              <button v-if="it.qty > 1" @click="qty(i,-1)">−</button>
              <input class="q-input" type="number" min="1" :max="it.stock" inputmode="numeric"
                     :value="it.qty"
                     @input="setQty(i, $event.target.value)"
                     @focus="$event.target.select()"
                     @blur="$event.target.value = cart[i].qty">
              <span class="unit-tag">{{ it.unit }}</span>
              <button @click="qty(i,1)" :disabled="it.qty >= it.stock">＋</button>
            </div>
          </div>
        </div>

        <div class="cart-foot">
          <div class="tot-row"><span>{{ t('Subtotal') }}</span><span class="num">{{ fmtP(subtotal) }}</span></div>
          <div class="tot-row grand"><span>{{ t('TOTAL') }}</span><span>{{ fmtP(total) }}</span></div>
          <button class="btn-checkout" :disabled="!cart.length" @click="openCheckout">
            💰 {{ t('Charge') }} {{ fmtP(total) }}
          </button>
        </div>
      </aside>
    </div>
  </div>

  

  
  <div class="modal-mask" v-if="clockModal" @click.self="clockModal=false">
    <div class="modal" style="max-width:400px">
      <div class="modal-head"><h3>{{ t('▶ Clock in') }}</h3><button class="modal-close" @click="clockModal=false">✕</button></div>
      <div class="modal-body">
        <div class="f">
          <label>{{ t('Starting cash in drawer (₱)') }}</label>
          <input type="number" min="0" step="0.01" v-model.number="startingCash">
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="clockModal=false">{{ t('Cancel') }}</button>
        <button class="btn btn-primary" @click="clockIn">{{ t('Clock in') }}</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="payModal" @click.self="payModal=false">
    <div class="modal">
      <div class="modal-head"><h3>{{ t('💰 Checkout') }}</h3><button class="modal-close" @click="payModal=false">✕</button></div>
      <div class="modal-body">
        <div class="pay-summary">
          <div class="pay-line"><span>{{ t('Items') }}</span><b>{{ cartCount }}</b></div>
          <div class="pay-line"><span>{{ t('Subtotal') }}</span><span>{{ fmtP(subtotal) }}</span></div>
          <div class="pay-line"><span>{{ t('Discount') }}</span><span>−{{ fmtP(discountPeso) }}</span></div>
          <div class="pay-line total"><span>{{ t('TOTAL') }}</span><span>{{ fmtP(total) }}</span></div>
        </div>

        <div class="f">
          <label>{{ t('Discount') }}</label>
          <select v-model.number="discPct">
            <option :value="0">{{ t('0% — None') }}</option>
            <option :value="5">{{ t('5% — Senior Citizen / PWD') }}</option>
          </select>
        </div>

        <div class="f">
          <label>{{ t('Payment method') }}</label>
          <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--green-50);font-weight:800;color:var(--green-800)">💵 {{ t('Cash') }}</div>
        </div>

        <div class="f" v-if="payMethod==='cash'">
          <label>{{ t('Amount received (₱)') }}</label>
          <input type="number" min="0" step="0.01" v-model.number="tendered" ref="tenderInput">
          <div class="quick-cash">
            <button @click="tendered = exactCash">{{ t('Exact') }}</button>
            <button v-for="b in quickBills" :key="b" @click="tendered = b">₱{{ b.toLocaleString() }}</button>
            <button @click="tendered = Math.ceil(total/100)*100">{{ t('Round ↑') }}</button>
          </div>
        </div>

        <div class="pay-line" style="font-size:16px" v-if="payMethod==='cash'">
          <span>{{ t('Change') }}</span><b :style="{color: change < 0 ? 'var(--danger)' : 'var(--green-700)'}">{{ fmtP(change) }}</b>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="payModal=false">{{ t('Back') }}</button>
        <button class="btn btn-primary" @click="checkout" :disabled="paying || (payMethod==='cash' && change < 0)">
          {{ paying ? t('Processing…') : t('✔ Complete sale') }}
        </button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="hist.open" @click.self="hist.open=false">
    <div class="modal" style="max-width:600px">
      <div class="modal-head">
        <h3>🧾 {{ hist.scope==='shift' ? t('Sales this shift') : t('My sales today') }}</h3>
        <button class="modal-close" @click="hist.open=false">✕</button>
      </div>
      <div class="modal-body" style="max-height:58vh;overflow-y:auto">
        <div class="bm-empty" v-if="!hist.list.length">
          <div class="big">🧾</div>
          {{ t('No transactions') }} {{ hist.scope==='shift' ? t('for this shift yet') : t('today yet') }}.<br>
          {{ t('Every new clock in starts a fresh history.') }}
        </div>
        <table class="tbl" v-else>
          <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Time') }}</th><th class="num">{{ t('Items') }}</th><th>{{ t('Pay') }}</th><th class="num">{{ t('Total') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in hist.list" :key="s.id">
              <td class="strong">
                {{ s.receipt_no }}
                <span v-if="s.status==='voided'" class="badge b-red" style="margin-left:6px">VOID</span>
              </td>
              <td class="muted">{{ fmtT(s.created_at) }}</td>
              <td class="num">{{ s.items }}</td>
              <td><span class="badge" :class="s.payment_method==='cash' ? 'b-green' : 'b-amber'">{{ s.payment_method }}</span></td>
              <td class="num strong">{{ fmtP(s.total_amount) }}</td>
              <td style="white-space:nowrap">
                <template v-if="s.status !== 'voided'">
                  <button class="btn btn-outline btn-sm" @click="viewReceipt(s)">👁 {{ t('View') }}</button>
                  <button class="btn btn-outline btn-sm" @click="reprint(s.id)">🖨 {{ t('Reprint') }}</button>
                  <button class="btn btn-outline btn-sm" @click="voidSale(s)">⛔ {{ t('Void') }}</button>
                </template>
                <template v-else>
                  <button class="btn btn-outline btn-sm" @click="viewReceipt(s)">👁 {{ t('View') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="modal-foot" style="justify-content:space-between;align-items:center">
        <div style="font-weight:800;color:var(--green-800);font-size:15px">
          {{ hist.count }} {{ hist.count===1 ? t('transaction') : t('transactions') }} · {{ fmtP(hist.total) }}
        </div>
        <button class="btn btn-primary" @click="hist.open=false">{{ t('Close') }}</button>
      </div>
    </div>
  </div>

  
  <div class="modal-mask" v-if="preview.open" @click.self="preview.open=false">
    <div class="modal" style="max-width:430px">
      <div class="modal-head">
        <h3>👁 {{ t('Receipt preview') }} <span class="muted" style="font-size:12.5px;font-weight:600" v-if="preview.receiptNo">· {{ preview.receiptNo }}</span></h3>
        <button class="modal-close" @click="preview.open=false">✕</button>
      </div>
      <div class="modal-body" style="display:grid;place-items:center;background:var(--green-50)">
        <div class="loading-row" v-if="preview.loading">{{ t('Loading receipt…') }}</div>
        <iframe v-show="!preview.loading" :src="preview.url" class="receipt-frame" @load="preview.loading=false" title="Receipt preview"></iframe>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" @click="preview.open=false">{{ t('Close') }}</button>
        <button class="btn btn-primary" @click="reprint(preview.saleId)">🖨 {{ t('Print') }}</button>
      </div>
    </div>
  </div>

</div>

<script src="Assets/vue.global.prod.js?v=2"></script>
<script src="Assets/sweetalert2.min.js?v=2"></script>
<script>
const ME = <?= json_encode($me) ?>;
const { createApp, ref, reactive, computed, onMounted } = Vue;

createApp({
  setup() {
    const me = ME;

    
    const lang = ref(localStorage.getItem('mh_lang') || 'en');
    function saveLang() { localStorage.setItem('mh_lang', lang.value); }
    const FIL = {
      '▶ Clock in': '▶ Time in', '⏸ Clock out': '⏸ Time-out', 'Clock in now': 'Time in na',
      'Clock in to start selling': 'Mag-time in para makapagbenta',
      'You need an active shift before you can select products, add to cart and check out.': 'Kailangan ng aktibong shift bago makapili ng produkto, mag-add sa cart, at mag-checkout.',
      'Starting cash in drawer (₱)': 'Panimulang pera sa drawer (₱)',
      'Clocked in since': 'Naka-time in mula noong', 'Clocked out': 'Naka-time out',
      '⬅ Admin panel': '⬅ Admin panel', '🧾 History': '🧾 Kasaysayan',
      'Notifications': 'Mga Abiso', 'Mark all read': 'Nabasa lahat', 'No notifications yet.': 'Wala pang abiso.',
      '⏻ Sign out': '⏻ Mag-sign out',
      'Scan barcode or search product, then press Enter…': 'I-scan ang barcode o hanapin ang produkto, tapos pindutin ang Enter…',
      'Cart': 'Cart', 'All': 'Lahat', 'Loading products…': 'Nilo-load ang mga produkto…',
      'No matching product.': 'Walang tugmang produkto.',
      '🛒 Current sale': '🛒 Kasalukuyang benta', 'item': 'item', 'items': 'mga item',
      'Clear all': 'Burahin lahat', 'Delete': 'Burahin',
      'Cart is empty.': 'Walang laman ang cart.', 'Scan or click a product to start.': 'I-scan o pindutin ang produkto para magsimula.',
      'Subtotal': 'Subtotal', 'Discount (₱)': 'Diskwento (₱)', 'TOTAL': 'KABUUAN', 'Charge': 'Singilin',
      'Name of product': 'Pangalan ng produkto', 'Price': 'Presyo', 'Quantity': 'Dami',
      'Void': 'I-void', 'Void item': 'I-void ang item', 'Clear all items': 'Burahin lahat ng items',
      '💰 Checkout': '💰 Bayad', 'Items': 'Mga item', 'Discount': 'Diskwento',
      'Payment method': 'Paraan ng bayad', '💵 Cash': '💵 Cash', 'Amount received (₱)': 'Halagang binayad (₱)',
      'Discount above 10% — supervisor password': 'Diskwentong lagpas 10% — password ng supervisor',
      '0% — None': '0% — Wala', '5% — Senior Citizen / PWD': '5% — Senior Citizen / PWD',
      'Sale completed — receipt printing.': 'Tapos na ang benta — nagpi-print ang resibo.',
      'Checkout was already completed — reprinting the original receipt.': 'Tapos na itong checkout — ini-print muli ang orihinal na resibo.',
      'Receipt sent to the printer.': 'Naipadala ang resibo sa printer.',
      'Printing failed — check that the printer is on and has paper.': 'Hindi na-print — tingnan kung naka-on at may papel ang printer.',
      'Authorize void': 'Awtorisasyon ng void', 'Void sale': 'I-void ang benta', 'Reason (required)': 'Dahilan (kailangan)',
      'Exact': 'Eksakto', 'Round ↑': 'Round ↑', 'Change': 'Sukli', 'Back': 'Bumalik',
      'Processing…': 'Pinoproseso…', '✔ Complete sale': '✔ Tapusin ang benta',
      'Sale completed!': 'Tapos na ang benta!', 'Receipt': 'Resibo', 'Paid': 'Bayad',
      'New sale': 'Panibagong benta', '🖨 Print receipt': '🖨 I-print ang resibo',
      'Sales this shift': 'Benta sa shift na ito', 'My sales today': 'Benta ko ngayong araw',
      'No transactions': 'Walang transaksyon', 'for this shift yet': 'sa shift na ito', 'today yet': 'ngayong araw',
      'Every new clock in starts a fresh history.': 'Bago ang history sa bawat bagong time in.',
      'Reprint': 'I-print muli', 'transaction': 'transaksyon', 'transactions': 'mga transaksyon',
      'Close': 'Isara', 'Receipt preview': 'Preview ng resibo', 'Loading receipt…': 'Nilo-load ang resibo…',
      'Print': 'I-print', 'Time': 'Oras', 'Total': 'Kabuuan', 'View': 'Tingnan', 'Pay': 'Bayad',
      'Cancel': 'Kanselahin', 'Clock in': 'Time in',
    };
    function t(s) { return lang.value === 'fil' ? (FIL[s] || s) : s; }
    const langOpen = ref(false);
    function pickLang(v) { lang.value = v; saveLang(); langOpen.value = false; }
    document.addEventListener('click', () => { langOpen.value = false; });
    const loading = ref(true);
    const products = ref([]);
    const cats = ref([]);
    const cat = ref(0);
    const search = ref('');
    const scanInput = ref(null);

    
    const savedCart = JSON.parse(localStorage.getItem('mh_cart') || '[]');
    const cart = ref(Array.isArray(savedCart) ? savedCart.filter(i => i && i.id && i.qty > 0) : []);
    const cartOpen = ref(false);
    const discPct = ref(0);   /* 0 = none, 5 = Senior Citizen — whitelist enforced server-side */
    function persistCart() {
      localStorage.setItem('mh_cart', JSON.stringify(cart.value));
    }
    const payModal = ref(false);
    const payMethod = ref('cash');
    const tendered = ref(0);
    const tenderInput = ref(null);
    const paying = ref(false);

    const clockModal = ref(false);
    const startingCash = ref(0);
    const session = reactive({ open: null });

    const bellOpen = ref(false);
    const userOpen = ref(false);
    const notifs = ref([]);
    const unread = ref(0);

    
    const hist = reactive({ open: false, loading: false, list: [], scope: 'shift', total: 0, count: 0 });
    
    const preview = reactive({ open: false, loading: false, url: '', saleId: 0, receiptNo: '' });

    
    const fmtP = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtT = s => s ? new Date(s.replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '';
    const initials = computed(() => me.full_name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase());
    function timeAgo(s) {
      const d = new Date(s.replace(' ', 'T'));
      const sec = Math.floor((Date.now() - d.getTime()) / 1000);
      if (sec < 60) return 'just now';
      if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
      if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
      return Math.floor(sec / 86400) + 'd ago';
    }
    const catIcon = c => ({
      'Tools': '🔨', 'Electrical': '💡', 'Plumbing': '🚰', 'Paint & Finishing': '🎨',
      'Building Materials': '🧱', 'Hardware & Fasteners': '🔩', 'Safety & Protection': '⛑️',
    }[c] || '📦');

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

    
    
    const filtered = computed(() => {
      let list = products.value;
      const terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
      if (terms.length) {
        list = list.filter(p => {
          const hay = (p.name + ' ' + (p.barcode || '')).toLowerCase();
          return terms.every(t => hay.includes(t));
        });
      }
      return list;
    });

    async function loadProducts() {
      loading.value = true;
      const r = await api('api_inventory_v2.php?action=list&category=' + cat.value);
      if (r.success) {
        products.value = r.products;
        
        if (cart.value.length) {
          const byId = {};
          products.value.forEach(p => byId[p.id] = p);
          cart.value = cart.value
            .filter(i => byId[i.id] && byId[i.id].display_qty > 0)
            .map(i => {
              const p = byId[i.id];
              i.name = p.name;
              i.price = Number(p.selling_price);
              i.unit = p.unit;
              i.stock = p.display_qty;
              if (i.qty > p.display_qty) i.qty = p.display_qty;
              return i;
            });
          persistCart();
        }
      }
      loading.value = false;
    }
    async function loadCats() {
      const r = await api('api_inventory_v2.php?action=categories');
      if (r.success) cats.value = r.categories;
    }

    
    const cartCount = computed(() => cart.value.reduce((s, i) => s + i.qty, 0));
    const subtotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.qty, 0));
    const discountPeso = computed(() => Math.round(subtotal.value * (discPct.value || 0)) / 100);
    const total = computed(() => Math.max(0, subtotal.value - discountPeso.value));
    const exactCash = computed(() => Math.ceil(total.value * 100) / 100);
    const quickBills = computed(() => {
      const t = total.value;
      const bills = [50, 100, 200, 500, 1000];
      return [...new Set(bills.filter(b => b >= t).concat(bills.slice(-2)))].slice(0, 4);
    });

    
    function inCartQty(pid) {
      const it = cart.value.find(i => i.id === pid);
      return it ? it.qty : 0;
    }
    function avail(p) {
      return p.display_qty - inCartQty(p.id);
    }
    function addToCart(p) {
      if (!session.open) return toast('warning', 'Please clock in first to start selling');
      if (p.display_qty <= 0) return toast('warning', p.name + ' is out of stock on the display shelves');
      if (window.innerWidth <= 1000) cartOpen.value = true; 
      const found = cart.value.find(i => i.id === p.id);
      if (found) {
        if (found.qty >= p.display_qty) return toast('warning', 'Only ' + p.display_qty + ' ' + p.unit + ' on display');
        found.qty++;
      } else {
        cart.value.push({ id: p.id, name: p.name, price: Number(p.selling_price), qty: 1, unit: p.unit, stock: p.display_qty });
      }
      persistCart();
    }
    function qty(i, d) {
      const it = cart.value[i];
      const next = it.qty + d;
      if (next <= 0) {
        cart.value.splice(i, 1);
        persistCart();
        /* audit trail — makikita sa History Logs ng admin */
        api('api_session.php?action=void_log', { kind: 'item', items: 'Removed ' + it.qty + ' ' + it.unit + ' ' + it.name + ' (' + fmtP(it.price * it.qty) + ') by ' + me.full_name });
        return;
      }
      if (next > it.stock) { toast('warning', 'Only ' + it.stock + ' ' + it.unit + ' on display'); return; }
      it.qty = next;
      persistCart();
    }

    
    
    async function askVoidPassword(title) {
      const res = await Swal.fire({
        title: '\ud83d\udd10 ' + title,
        html: '<div style="font-size:12.5px;color:#52636f">Enter the void password to continue.</div>',
        input: 'password',
        inputPlaceholder: 'Void password',
        showCancelButton: true,
        confirmButtonText: 'Confirm',
        confirmButtonColor: '#16a34a',
        reverseButtons: true,
      });
      if (!res.isConfirmed) return null;
      const r = await api('api_session.php?action=void_auth', { password: res.value || '' });
      if (r.success) return res.value || '';
      toast('error', r.message || 'Wrong password');
      return null;
    }
    function clearCart() {
      if (!cart.value.length) return;
      askVoidPassword(t('Clear all items')).then(pw => {
        if (pw === null) return;
        /* audit trail with the actual cancelled cart contents (r4: cancelled transactions) */
        const details = 'Cart cleared by ' + me.full_name + ': ' + cart.value
          .map(i => i.qty + 'x ' + i.name + ' (' + fmtP(i.price * i.qty) + ')').join(', ')
          + (discPct.value ? ' — SC discount ' + fmtP(discountPeso.value) : '');
        const n = cart.value.length;
        cart.value = [];
        discPct.value = 0;
        persistCart();
        api('api_session.php?action=void_log', { kind: 'clear', items: details });
        toast('success', 'Cart cleared (' + n + ' item' + (n === 1 ? '' : 's') + ').');
      });
    }
    
    function setQty(i, val) {
      const it = cart.value[i];
      let n = parseInt(val, 10);
      if (isNaN(n)) return;                    
      if (n < 1) n = 1;
      if (n > it.stock) {
        n = it.stock;
        toast('warning', 'Only ' + it.stock + ' ' + it.unit + ' in stock');
      }
      it.qty = n;
      persistCart();
    }
    
    async function scanEnter() {
      const s = search.value.trim();
      if (!s) return;
      /* case-insensitive barcode match — scanner input must always hit (r4) */
      const up = s.toUpperCase();
      let p = products.value.find(x => (x.barcode || '').toUpperCase() === up);
      if (!p) p = filtered.value[0];
      if (!p) {
        await loadProducts();
        p = products.value.find(x => (x.barcode || '').toUpperCase() === up);
        if (!p) p = filtered.value[0];
      }
      if (p) {
        addToCart(p);
        search.value = '';
        scanInput.value?.focus();
      } else {
        toast('info', 'No product matches "' + s + '"');
      }
    }

    
    function openCheckout() {
      tendered.value = 0;
      payMethod.value = 'cash';
      discPct.value = 0;            /* fresh sale — no discount unless chosen */
      payRef.value = newPayRef();   /* one idempotency key per checkout attempt (r4) */
      payModal.value = true;
      setTimeout(() => tenderInput.value?.focus(), 120);
    }
    const change = computed(() => (tendered.value || 0) - total.value);

    function newPayRef() {
      return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'k' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }
    const payRef = ref('');

    async function checkout() {
      if (!session.open) return toast('warning', 'Please clock in first');
      if (paying.value) return;
      paying.value = true;
      const r = await api('api_checkout.php', {
        items: cart.value.map(i => ({ product_id: i.id, qty: i.qty })),
        amount_paid: tendered.value,
        discount_pct: discPct.value || 0,
        payment_method: payMethod.value,
        idempotency_key: payRef.value,
      });
      paying.value = false;
      if (r.success) {
        payRef.value = newPayRef();  /* fresh key for the next sale */
        payModal.value = false;
        cart.value = [];
        discPct.value = 0;
        persistCart();
        loadProducts();
        loadUnread();
        if (hist.open) loadHistory();
        scanInput.value?.focus();   /* ready for the next scan immediately */
        toast('success', r.duplicate
          ? t('Checkout was already completed — reprinting the original receipt.')
          : t('Sale completed — receipt printing.'));
      } else {
        toast('error', r.message);
        loadProducts();
      }
    }

    /* post-sale void — super admin only, password + reason, stock restored (r4) */
    async function voidSale(s) {
      const r1 = await Swal.fire({
        title: '⛔ ' + t('Void sale') + ' ' + s.receipt_no + '?',
        html: '<div style="font-size:12.5px;color:#52636f">Stock will be returned and the sale will be excluded from all reports.</div>',
        input: 'text',
        inputPlaceholder: t('Reason (required)'),
        showCancelButton: true,
        confirmButtonText: 'Next',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
      });
      if (!r1.isConfirmed) return;
      if (!r1.value || !String(r1.value).trim()) return toast('warning', 'A reason is required to void a sale');
      const pw = await askVoidPassword(t('Authorize void'));
      if (pw === null) return;
      const r = await api('api_void_sale.php', { sale_id: s.id, password: pw, reason: String(r1.value).trim() });
      if (r.success) {
        toast('success', r.message);
        loadHistory();
        loadProducts();
      } else {
        toast('error', r.message);
      }
    }
    /* print straight to the thermal printer (r4) — no dialog, no popup */
    async function printRaw(saleId) {
      const r = await api('api_print.php?action=raw&sale_id=' + saleId, {});
      toast(r.success ? 'success' : 'error', r.message);
    }

    
    async function loadSession() {
      const r = await api('api_session.php?action=status');
      if (r.success) session.open = r.open_session;
    }
    async function loadHistory() {
      const r = await api('api_receipts.php?action=history');
      if (r.success) {
        hist.list = r.sales;
        hist.scope = r.scope;
        hist.total = r.total;
        hist.count = r.count;
      }
    }
    function openHistory() {
      bellOpen.value = false;
      userOpen.value = false;
      hist.open = true;
      loadHistory();
    }
    function reprint(saleId) { printRaw(saleId); }
    function viewReceipt(s) {
      preview.saleId = s.id;
      preview.receiptNo = s.receipt_no;
      preview.url = 'api_print.php?sale_id=' + s.id + '&preview=1';
      preview.loading = true;
      preview.open = true;
    }
    async function clockIn() {
      const r = await api('api_session.php?action=clock_in', { starting_cash: startingCash.value || 0 });
      if (r.success) {
        clockModal.value = false;
        toast('success', r.message + ' — shift started, POS unlocked!');
        loadSession();
        if (hist.open) loadHistory();
      }
      else toast('error', r.message);
    }
    async function clockOut() {
      const c = await Swal.fire({
        title: 'Clock out?', icon: 'question', showCancelButton: true,
        confirmButtonColor: '#16a34a', confirmButtonText: 'Clock out',
      });
      if (!c.isConfirmed) return;
      
      await loadHistory();
      const startCash = Number(session.open?.starting_cash || 0);
      const salesTotal = Number(hist.total || 0);
      const expected = Math.round((startCash + salesTotal) * 100) / 100;
      
      const c2 = await Swal.fire({
        title: 'Cash in drawer (₱)',
        html:
          '<div style="text-align:left;font-size:13.5px;line-height:1.8;margin-bottom:8px">' +
          'Starting cash: <b>' + fmtP(startCash) + '</b><br>' +
          'Sales this shift (' + hist.count + '): <b>' + fmtP(salesTotal) + '</b><br>' +
          'Expected cash: <b style="color:#15803d;font-size:16px">' + fmtP(expected) + '</b></div>' +
          '<div style="font-size:11.5px;color:#7a8a80">Auto-computed from the shift history — not editable.</div>',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        confirmButtonText: 'Confirm & clock out',
        reverseButtons: true,
      });
      if (!c2.isConfirmed) return;
      const r = await api('api_session.php?action=clock_out', { ending_cash: expected });
      if (r.success) { toast('success', r.message + ' — POS is now locked.'); loadSession(); if (hist.open) loadHistory(); }
      else toast('error', r.message);
    }

    
    async function loadUnread() {
      const r = await api('api_notifications.php?action=unread_count');
      if (r.success) unread.value = r.count;
    }
    function toggleBell() {
      bellOpen.value = !bellOpen.value;
      userOpen.value = false;
      if (bellOpen.value) loadNotifs();
    }
    async function loadNotifs() {
      const r = await api('api_notifications.php?action=list');
      if (r.success) notifs.value = r.notifications;
    }
    async function readNotif(n) {
      if (!Number(n.is_read)) { await api('api_notifications.php?action=mark_read', { id: n.id }); n.is_read = 1; loadUnread(); }
    }
    async function markAllRead() {
      await api('api_notifications.php?action=mark_all_read', {});
      notifs.value.forEach(n => n.is_read = 1);
      loadUnread();
    }

    
    async function logout(expired) {
      await api('auth.php?action=logout', {});
      location.href = expired ? 'Login.php?expired=1' : 'Login.php';
    }

    /* global scanner capture (r4): hardware scanners type like a keyboard and
       end with Enter. If the scan box is not focused (cashier clicked elsewhere),
       the keystrokes would be lost — so listen at document level: any burst of
       code characters ending in Enter outside an input is treated as a scan. */
    let scanBuf = '';
    let scanTimer = null;
    function globalScanKey(e) {
      const ae = document.activeElement;
      const typing = ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT' || ae.isContentEditable);
      if (typing) { scanBuf = ''; return; }          /* normal input path handles it */
      if (e.key === 'Enter') {
        if (scanBuf.length >= 4) {
          search.value = scanBuf;
          scanEnter();
        }
        scanBuf = '';
        return;
      }
      if (e.key.length === 1 && /[A-Za-z0-9\-]/.test(e.key)) {
        scanBuf += e.key;
        clearTimeout(scanTimer);
        scanTimer = setTimeout(() => { scanBuf = ''; }, 600);
      } else if (e.key !== 'Shift') {
        scanBuf = '';
      }
    }

    onMounted(() => {
      loadProducts();
      loadCats();
      loadSession();
      loadUnread();
      setInterval(loadUnread, 30000);
      
      setInterval(loadProducts, 60000);
      document.addEventListener('click', () => { bellOpen.value = false; userOpen.value = false; });
      document.addEventListener('keydown', globalScanKey);
      scanInput.value?.focus();

      /* idle auto-logout (r4: session policy) — the server enforces 2 hours
         (SESSION_IDLE_LIMIT); the UI warns 5 minutes before, then signs out */
      let lastActive = Date.now();
      let idleWarned = false;
      ['click', 'keydown', 'mousemove', 'touchstart'].forEach(ev =>
        document.addEventListener(ev, () => { lastActive = Date.now(); }, { passive: true })
      );
      setInterval(() => {
        const idleMin = (Date.now() - lastActive) / 60000;
        if (idleMin >= 119) { logout(true); return; }
        if (idleMin >= 115 && !idleWarned) {
          idleWarned = true;
          Swal.fire({
            title: 'Still there?',
            html: '<div style="font-size:13px;color:#52636f">For security, idle sessions are signed out after 2 hours.</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Continue working',
            cancelButtonText: 'Sign out now',
            allowOutsideClick: false,
            timer: 150000,
            timerProgressBar: true,
          }).then(res => {
            if (res.isConfirmed) { lastActive = Date.now(); idleWarned = false; }
            else { logout(true); }
          });
        }
      }, 30000);
    });

    return {
      lang, saveLang, t, langOpen, pickLang,
      me, loading, products, cats, cat, search, scanInput, filtered, catIcon, loadProducts, avail,
      cart, cartCount, subtotal, total, discPct, discountPeso, addToCart, qty, setQty, clearCart, scanEnter, cartOpen,
      payModal, payMethod, tendered, tenderInput, paying, openCheckout, change, checkout, voidSale,
      exactCash, quickBills,
      session, clockModal, startingCash, clockIn, clockOut,
      hist, openHistory, reprint, viewReceipt, preview, loadHistory,
      bellOpen, userOpen, notifs, unread, loadNotifs, readNotif, markAllRead,
      logout,
      fmtP, fmtT, initials, timeAgo, toast, toggleBell,
    };
  },
}).mount('#app');
</script>
</body>
</html>
