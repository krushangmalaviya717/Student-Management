// PWA Installation & Service Worker Manager v4
let deferredPrompt = null;

// Standalone mode check
function isRunningStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches 
    || window.navigator.standalone === true 
    || document.referrer.includes('android-app://');
}

// iOS Device check (handles modern iPadOS as well)
function isIosDevice() {
  const ua = window.navigator.userAgent.toLowerCase();
  return /iphone|ipad|ipod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

// Listen for native install prompt (Android Chrome / Edge / Desktop)
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  console.log('PWA: Native prompt ready');
});

// Hide buttons if already installed
function checkInstallState() {
  if (isRunningStandalone()) {
    document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => {
      el.style.display = 'none';
    });
  }
}

// Master Install Trigger
window.triggerPwaInstall = async function() {
  if (isRunningStandalone()) {
    alert('એપ પહેલાથી જ તમારા મોબાઇલમાં ઇન્સ્ટોલ થયેલી છે!');
    return;
  }

  // 1. Android / Desktop with native prompt available
  if (deferredPrompt) {
    try {
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      console.log('PWA outcome:', choice.outcome);
      if (choice.outcome === 'accepted') {
        deferredPrompt = null;
        document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => el.style.display = 'none');
      }
      return;
    } catch (err) {
      console.warn('Prompt error:', err);
    }
  }

  // 2. iPhone / iPad (iOS Safari)
  if (isIosDevice()) {
    showIosModal();
    return;
  }

  // 3. Android fallback (if prompt hasn't fired yet)
  showAndroidModal();
};

function showIosModal() {
  const existing = document.getElementById('iosInstallModal');
  if (existing) existing.remove();

  const html = `
    <div id="iosInstallModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;backdrop-filter:blur(4px);animation:fadeIn 0.2s ease;">
      <div style="background:#ffffff;border-radius:24px 24px 0 0;padding:26px 22px;max-width:500px;width:100%;text-align:center;box-shadow:0 -10px 30px rgba(0,0,0,0.3);box-sizing:border-box;">
        <div style="width:44px;height:5px;background:#cbd5e1;border-radius:5px;margin:0 auto 16px;"></div>
        
        <div style="width:56px;height:56px;background:linear-gradient(135deg, #1e3a8a, #3b82f6);border-radius:14px;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.8rem;box-shadow:0 4px 12px rgba(30,58,138,0.25);">
          📱
        </div>

        <h4 style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:1.2rem;">iPhone માં એપ ઇન્સ્ટોલ કરો</h4>
        <p style="font-size:0.85rem;color:#64748b;margin-bottom:20px;">હોમ સ્ક્રીન પર ઉમેરવા માટે નીચેના ૩ સરળ સ્ટેપ્સ કરો:</p>

        <div style="text-align:left;font-size:0.92rem;color:#334155;margin-bottom:24px;background:#f8fafc;padding:16px;border-radius:14px;border:1px solid #e2e8f0;">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">1</span>
            <div>Safari માં નીચે <b>Share (શેર)</b> બટન <span style="display:inline-block;padding:2px 8px;background:#e2e8f0;border-radius:6px;font-size:1rem;">⎋</span> દબાવો.</div>
          </div>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">2</span>
            <div>મેનુમાં નીચે સ્ક્રોલ કરીને <b>'Add to Home Screen' (➕)</b> પસંદ કરો.</div>
          </div>
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">3</span>
            <div>ઉપર જમણી બાજુ <b>'Add'</b> પર ક્લિક કરો.</div>
          </div>
        </div>

        <button type="button" style="width:100%;padding:12px;background:#1e3a8a;color:#fff;border:none;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;" onclick="document.getElementById('iosInstallModal').remove();">
          સમજાઈ ગયું (Close)
        </button>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', html);
}

function showAndroidModal() {
  const existing = document.getElementById('androidInstallModal');
  if (existing) existing.remove();

  const html = `
    <div id="androidInstallModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);">
      <div style="background:#ffffff;border-radius:20px;padding:26px 20px;max-width:380px;width:100%;text-align:center;box-shadow:0 15px 35px rgba(0,0,0,0.3);box-sizing:border-box;">
        <div style="width:56px;height:56px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem;">
          📥
        </div>
        <h4 style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:1.15rem;">એપ ઇન્સ્ટોલ કરવા માટે</h4>
        <p style="font-size:0.88rem;color:#475569;margin-bottom:20px;line-height:1.5;">
          Chrome બ્રાઉઝરમાં ઉપર જમણી બાજુ <b>૩ ટપકાં (⋮)</b> પર ક્લિક કરો અને <b>"Install app"</b> અથવા <b>"Add to Home screen"</b> પસંદ કરો.
        </p>
        <button type="button" style="width:100%;padding:12px;background:#1e3a8a;color:#fff;border:none;border-radius:12px;font-weight:700;font-size:0.95rem;cursor:pointer;" onclick="document.getElementById('androidInstallModal').remove();">
          સમજાઈ ગયું (OK)
        </button>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', html);
}

// Attach click listeners to all install trigger buttons
function attachListeners() {
  document.querySelectorAll('.pwa-install-trigger').forEach(btn => {
    btn.onclick = function(e) {
      e.preventDefault();
      window.triggerPwaInstall();
    };
  });
}

window.addEventListener('appinstalled', () => {
  document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => el.style.display = 'none');
});

document.addEventListener('DOMContentLoaded', () => {
  checkInstallState();
  attachListeners();
});

// Register Service Worker with relative path
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/public/student/sw.js', { scope: '/public/student/' })
      .then((reg) => console.log('PWA: SW active, scope:', reg.scope))
      .catch((err) => console.warn('PWA: SW note:', err));
  });
}
