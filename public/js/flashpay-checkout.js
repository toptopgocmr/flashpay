/*!
 * FlashPay Checkout widget (§4.7.5) — bouton « Payer avec FlashPay ».
 * Usage :
 *   <script src="https://api.flashpay.cg/js/flashpay-checkout.js"></script>
 *   <div data-flashpay-button data-create-url="/api/flashpay/create" data-label="Payer avec FlashPay"></div>
 * data-create-url : route de VOTRE serveur qui crée le payment intent avec la clé
 * secrète (POST /api/v1/payment-intents) et renvoie son JSON ({ checkout_url }).
 */
(function () {
  var css = '.fp-btn{display:inline-flex;align-items:center;gap:8px;background:#1b4fd8;color:#fff;border:0;border-radius:12px;padding:12px 20px;font:700 16px system-ui,sans-serif;cursor:pointer}.fp-btn:disabled{opacity:.6;cursor:wait}.fp-err{color:#c62828;font:13px system-ui;margin-top:6px}';
  var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

  function mount(el) {
    if (el.__fp) return; el.__fp = true;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'fp-btn';
    btn.innerHTML = '<span style="font-size:18px">⚡</span>' + (el.getAttribute('data-label') || 'Payer avec FlashPay');
    var err = document.createElement('div'); err.className = 'fp-err';
    el.appendChild(btn); el.appendChild(err);
    btn.addEventListener('click', function () {
      btn.disabled = true; err.textContent = '';
      var body = el.getAttribute('data-payload');
      fetch(el.getAttribute('data-create-url'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: body || '{}'
      }).then(function (r) { return r.json(); }).then(function (pi) {
        if (!pi || !pi.checkout_url) throw new Error((pi && pi.error && pi.error.message) || 'Paiement indisponible');
        var ua = navigator.userAgent || '';
        // Sur mobile, tente d'ouvrir l'app FlashPay puis bascule sur la page web
        if (/Android|iPhone|iPad/i.test(ua) && pi.qr_payload) {
          var t = setTimeout(function () { window.location.href = pi.checkout_url; }, 1200);
          window.addEventListener('pagehide', function () { clearTimeout(t); }, { once: true });
          window.location.href = pi.qr_payload;
        } else {
          window.location.href = pi.checkout_url;
        }
      }).catch(function (e) { err.textContent = e.message; btn.disabled = false; });
    });
  }

  function init() { document.querySelectorAll('[data-flashpay-button]').forEach(mount); }
  window.FlashPayCheckout = { init: init, mount: mount };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
