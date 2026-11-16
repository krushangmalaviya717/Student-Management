// PWA Installation & Service Worker Manager v3
let deferredPrompt = null;

// Check if running in standalone mode (already installed app)
const isAppStandalone = window.matchMedia('(display-mode: standalone)').matches 
  || window.navigator.standalone === true;

function updateInstallUi() {
  if (isAppStandalone) {
    // Hide all install buttons if user is already inside the installed PWA
    document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => {
      el.style.display = 'none';
    });
  }
}

// Listen for the beforeinstallprompt event (Android / Desktop Chrome)
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  console.log('PWA: beforeinstallprompt event captured');
});

// Detect iOS devices
function isIosDevice() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// Global Trigger function for Install Buttons
window.triggerPwaInstall = async function() {
  if (isAppStandalone) {
    alert('એપ પહેલાથી જ તમારા મોબાઇલમાં ઇન્સ્ટોલ કરેલી છે!');
    return;
  }

  // 1. If native Chrome prompt is ready
  if (deferredPrompt) {
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    console.log(`PWA: User choice ${outcome}`);
    if (outcome === 'accepted') {
      deferredPrompt = null;
      document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => el.style.display = 'none');
    }
    return;
  }

  // 2. If user is on iPhone / iOS Safari
  if (isIosDevice()) {
    showIosInstallModal();
    return;
  }

  // 3. Fallback for Android / Other browsers where prompt didn't fire yet
  showAndroidFallbackModal();
};

function showIosInstallModal() {
  const modalHtml = `
    <div id="iosInstallModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;display:flex;align-items:flex-end;justify-content:center;backdrop-filter:blur(3px);">
      <div style="background:#fff;border-radius:20px 20px 0 0;padding:24px 20px;max-width:480px;width:100%;text-align:center;box-shadow:0 -10px 25px rgba(0,0,0,0.2);">
        <div style="width:40px;height:4px;background:#cbd5e1;border-radius:4px;margin:0 auto 16px;"></div>
        <h5 style="font-weight:700;color:#1e293b;margin-bottom:12px;">📱 iPhone માં એપ ઇન્સ્ટોલ કરો</h5>
        <div style="text-align:left;font-size:0.9rem;color:#475569;margin-bottom:20px;line-height:1.6;">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="background:#eff6ff;color:#2563eb;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;">૧</div>
            <div>Safari બ્રાઉઝરમાં નીચે <b>Share (શેર)</b> બટન <span style="font-size:1.2rem;">⎋</span> દબાવો.</div>
          </div>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="background:#eff6ff;color:#2563eb;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;">૨</div>
            <div>નીચે સ્ક્રોલ કરીને <b>'Add to Home Screen' (➕)</b> પસંદ કરો.</div>
          </div>
          <div style="display:flex;align-items:center;gap:12px;">
            <div style="background:#eff6ff;color:#2563eb;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;">૩</div>
            <div>ઉપર જમણી બાજુ <b>'Add'</b> પર ક્લિક કરો.</div>
          </div>
        </div>
        <button type="button" class="btn btn-primary w-100 py-2 rounded-pill fw-bold" onclick="document.getElementById('iosInstallModal').remove();">
          સમજાઈ ગયું (OK)
        </button>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function showAndroidFallbackModal() {
  const modalHtml = `
    <div id="androidInstallModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);">
      <div style="background:#fff;border-radius:18px;padding:24px 20px;max-width:380px;width:100%;text-align:center;box-shadow:0 15px 35px rgba(0,0,0,0.25);">
        <div style="width:50px;height:50px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.5rem;">
          <i class="bi bi-download"></i>
        </div>
        <h5 style="font-weight:700;color:#1e293b;margin-bottom:8px;">એપ ઇન્સ્ટોલ કરવા માટે</h5>
        <p style="font-size:0.88rem;color:#64748b;margin-bottom:16px;line-height:1.5;">
          Chrome બ્રાઉઝરમાં ઉપર જમણી બાજુ <b>૩ ટપકાં (⋮)</b> પર ક્લિક કરો અને <b>"Install app"</b> અથવા <b>"Add to Home screen"</b> પસંદ કરો.
        </p>
        <button type="button" class="btn btn-primary w-100 py-2 rounded-pill fw-bold" onclick="document.getElementById('androidInstallModal').remove();">
          સમજાઈ ગયું (OK)
        </button>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// App installed successfully event
window.addEventListener('appinstalled', () => {
  console.log('PWA: Installed successfully');
  document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => el.style.display = 'none');
});

// Run UI check when DOM is ready
document.addEventListener('DOMContentLoaded', updateInstallUi);

// Register Service Worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/public/student/sw.js')
      .then((reg) => console.log('PWA: SW Registered, scope:', reg.scope))
      .catch((err) => console.warn('PWA: SW Registration notice:', err));
  });
}
