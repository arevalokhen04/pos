<?php

require __DIR__ . '/db.php';
$LOGGED = current_user();
$LOGGED_HOME = null;
if ($LOGGED) {
    $LOGGED_HOME = match ($LOGGED['role']) {
        'cashier'     => 'cashier.php',
        'super_admin' => 'super_admin.php',
        default       => 'admin.php',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Marina's Hardware POS</title>
<link rel="stylesheet" href="Assets/sweetalert2.min.css?v=2">
<link rel="stylesheet" href="Assets/login.css?v=2">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
</head>
<body>
<div id="app" v-cloak>
  <div class="login-wrap">

    <!-- ===== left branding ===== -->
    <aside class="brand-side">
      <div class="brand-logo">
        <div class="logo-badge">🔧</div>
        <div class="name">MARINA'S <span>HARDWARE</span></div>
      </div>
      <h1>Hardware Store</h1>
      <p class="sub">
        Track stock in real time, ring up sales at the counter, approve restocking
        requests and read daily, weekly, monthly and overall sales reports.
      </p>
      <div class="brand-foot">© <?= date('Y') ?> Marina's Hardware — POS &amp; Inventory Management System</div>
    </aside>

    <!-- ===== right form ===== -->
    <main class="form-side">
      <div class="login-card">

        <!-- already signed in: show who + quick switch instead of silently redirecting -->
        <div class="signedin-note" v-if="signedIn">
          <div class="sn-row">
            <div class="sn-avatar">{{ signedIn.initials }}</div>
            <div>
              <div class="sn-name">You're signed in as <b>{{ signedIn.full_name }}</b></div>
              <div class="sn-role">{{ signedIn.role.replace('_',' ') }} account</div>
            </div>
          </div>
          <button class="btn-green" style="margin-top:13px" @click="location.href=signedIn.home">
            {{ signedIn.role === 'cashier' ? 'Continue to POS →' : 'Continue to dashboard →' }}
          </button>
          <button class="btn-ghost" style="margin-top:8px" @click="signOutSwitch">
            Sign out &amp; use another account
          </button>
        </div>

        <div class="card-tabs">
          <button :class="{active: tab==='login'}" @click="tab='login'">Sign in</button>
          <button :class="{active: tab==='register'}" @click="tab='register'">Register</button>
        </div>

        <!-- LOGIN -->
        <template v-if="tab==='login'">
          <h2>Welcome back 👋</h2>
          <p class="hint">Sign in to your Marina's Hardware account.</p>

          <div class="error-note" v-if="error">{{ error }}</div>

          <form @submit.prevent="login">
            <div class="field">
              <label>Username</label>
              <div class="input-wrap">
                <span class="ico">👤</span>
                <input v-model.trim="form.username" autocomplete="username" placeholder="e.g. admin1" required>
              </div>
            </div>
            <div class="field">
              <label>Password</label>
              <div class="input-wrap">
                <span class="ico">🔒</span>
                <input :type="showPass ? 'text':'password'" v-model="form.password"
                       autocomplete="current-password" placeholder="••••••••" required>
              </div>
            </div>
            <button class="btn-green" :disabled="loading">
              {{ loading ? 'Signing in…' : 'Sign in' }}
            </button>
          </form>
        </template>

        <!-- REGISTER -->
        <template v-else>
          <h2>Create account</h2>
          <p class="hint">Registers as cashier — the super admin activates it.</p>

          <div class="error-note" v-if="error">{{ error }}</div>

          <form @submit.prevent="register">
            <div class="field">
              <label>Full name</label>
              <div class="input-wrap">
                <span class="ico">🪪</span>
                <input v-model.trim="reg.full_name" placeholder="Juan Dela Cruz" required>
              </div>
            </div>
            <div class="field">
              <label>Username</label>
              <div class="input-wrap">
                <span class="ico">👤</span>
                <input v-model.trim="reg.username" placeholder="juandc" required>
              </div>
            </div>
            <div class="field">
              <label>Role</label>
              <select v-model="reg.role">
                <option value="cashier">Cashier</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="field">
              <label>Password (min 6 characters)</label>
              <div class="input-wrap">
                <span class="ico">🔒</span>
                <input type="password" v-model="reg.password" placeholder="••••••••" required>
              </div>
            </div>
            <button class="btn-green" :disabled="loading">
              {{ loading ? 'Submitting…' : 'Register' }}
            </button>
          </form>
        </template>

      </div>
    </main>
  </div>
</div>

<script src="Assets/vue.global.prod.js?v=2"></script>
<script src="Assets/sweetalert2.min.js?v=2"></script>
<script>
const SIGNED_IN = <?= $LOGGED ? json_encode([
    'full_name' => $LOGGED['full_name'],
    'role'      => $LOGGED['role'],
    'home'      => $LOGGED_HOME,
    'initials'  => strtoupper(mb_substr($LOGGED['full_name'], 0, 1)),
]) : 'null' ?>;

const { createApp, ref, reactive } = Vue;

createApp({
  setup() {
    const signedIn = ref(SIGNED_IN);
    const tab = ref('login');
    const loading = ref(false);
    const error = ref('');
    const showPass = ref(false);
    const form = reactive({ username: '', password: '' });
    const reg = reactive({ full_name: '', username: '', role: 'cashier', password: '' });

    async function api(action, body) {
      const res = await fetch('auth.php?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      return res.json();
    }

    async function login() {
      error.value = '';
      loading.value = true;
      try {
        const r = await api('login', form);
        if (r.success) {
          Swal.fire({ icon: 'success', title: r.message, timer: 900, showConfirmButton: false });
          setTimeout(() => location.href = r.redirect, 950);
        } else {
          error.value = r.message;
        }
      } catch (e) {
        error.value = 'Cannot reach the server. Is Apache running?';
      } finally {
        loading.value = false;
      }
    }

    async function register() {
      error.value = '';
      loading.value = true;
      try {
        const r = await api('register', reg);
        if (r.success) {
          await Swal.fire({ icon: 'success', title: 'Registered!', text: r.message });
          tab.value = 'login';
          form.username = reg.username;
          form.password = '';
        } else {
          error.value = r.message;
        }
      } finally {
        loading.value = false;
      }
    }

    async function signOutSwitch() {
      await fetch('auth.php?action=logout', { method: 'POST', body: '{}' });
      signedIn.value = null;
      Swal.fire({ icon: 'success', title: 'Signed out', text: 'You can now sign in with another account.', timer: 1400, showConfirmButton: false });
    }

    return { signedIn, signOutSwitch, tab, loading, error, showPass, form, reg, login, register };
  },
}).mount('#app');
</script>
</body>
</html>
