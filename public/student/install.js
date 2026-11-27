// Devangi Classes PWA Installation Manager v5
let deferredPrompt = null;

// Standalone mode check (is app already installed and running?)
function isRunningStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches 
    || window.navigator.standalone === true 
    || document.referrer.includes('android-app://');
}

// iOS Device check
function isIosDevice() {
  const ua = window.navigator.userAgent.toLowerCase();
  return /iphone|ipad|ipod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

// Listen for native install prompt as early as possible
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  console.log('PWA: Native beforeinstallprompt captured successfully!');
  
  // Show all install triggers
  document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => {
    el.style.display = '';
  });
});

// Hide buttons if already running inside installed standalone app
function checkInstallState() {
  if (isRunningStandalone()) {
    document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => {
      el.style.display = 'none';
    });
  }
}

// Master Install Trigger
window.triggerPwaInstall = async function() {
  // If already running inside installed app
  if (isRunningStandalone()) {
    alert('✅ આ એપ તમારા ફોનમાં પહેલેથી જ ઇન્સ્ટોલ કરેલી છે!');
    return;
  }

  // 1. Android / Chrome: Native 1-Click Install Prompt
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
      console.warn('PWA prompt error:', err);
    }
  }

  // 2. iPhone / iPad (iOS Safari)
  if (isIosDevice()) {
    showIosModal();
    return;
  }

  // 3. Android Fallback (if native prompt is delayed or user already added)
  showAndroidModal();
};

function showIosModal() {
  const existing = document.getElementById('iosInstallModal');
  if (existing) existing.remove();

  const html = `
    <div id="iosInstallModal" style="position:fixed;inset:0;background:rgba(15,23,42,0.75);z-index:999999;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;backdrop-filter:blur(6px);animation:fadeInIos 0.25s ease;">
      <div style="background:#ffffff;border-radius:28px 28px 0 0;padding:24px 20px 20px;max-width:480px;width:100%;text-align:center;box-shadow:0 -12px 35px rgba(0,0,0,0.3);box-sizing:border-box;position:relative;">
        
        <!-- Drag pill indicator -->
        <div style="width:42px;height:5px;background:#cbd5e1;border-radius:10px;margin:0 auto 16px;"></div>

        <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:6px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg, #1e3a8a, #2563eb);border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;box-shadow:0 4px 12px rgba(30,58,138,0.25);">
            ✂️
          </div>
          <h3 style="font-weight:800;color:#0f172a;margin:0;font-size:1.25rem;">iPhone માં એપ ઇન્સ્ટોલ કરો</h3>
        </div>
        <p style="font-size:0.86rem;color:#64748b;margin:0 0 18px;">તમારા ફોનમાં એપ ઉમેરવા માટે નીચેના ૩ સ્ટેપ જુઓ:</p>

        <!-- Steps list -->
        <div style="text-align:left;font-size:0.92rem;color:#1e293b;background:#f8fafc;padding:16px 14px;border-radius:16px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:14px;">
          
          <!-- Step 1 -->
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;flex-shrink:0;">1</span>
            <div style="line-height:1.45;flex:1;">
              Safari માં નીચે વચ્ચે આવેલું <b>Share</b> બટન દબાવો:
              <div style="margin-top:5px;display:inline-flex;align-items:center;gap:6px;background:#ffffff;padding:4px 12px;border-radius:8px;border:1.5px solid #007aff;color:#007aff;font-weight:700;box-shadow:0 2px 6px rgba(0,122,255,0.15);">
                <svg width="17" height="20" viewBox="0 0 20 24" fill="none" stroke="#007aff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v13M5 7l5-5 5 5M3 12v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9"/></svg>
                <span>આવું શેર બટન</span>
              </div>
            </div>
          </div>

          <!-- Step 2 -->
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;flex-shrink:0;">2</span>
            <div style="line-height:1.45;flex:1;">
              મેનુમાં નીચે સ્ક્રોલ કરીને 
              <span style="display:inline-flex;align-items:center;gap:4px;background:#ffffff;padding:2px 8px;border-radius:6px;border:1px solid #cbd5e1;font-weight:700;color:#0f172a;margin:2px 0;">
                <span style="font-size:1.1rem;color:#2563eb;line-height:1;">⊞</span> 'Add to Home Screen'
              </span>
              પસંદ કરો.
            </div>
          </div>

          <!-- Step 3 -->
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="background:#2563eb;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;flex-shrink:0;">3</span>
            <div style="line-height:1.45;flex:1;">
              ઉપર જમણી બાજુ આવેલું 
              <span style="background:#007aff;color:#fff;padding:2px 10px;border-radius:6px;font-weight:700;font-size:0.82rem;display:inline-block;">Add</span> 
              દબાવો.
            </div>
          </div>

        </div>

        <!-- Pointer to Bottom Safari Bar -->
        <div style="margin:16px 0 14px;padding:12px 14px;background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);border:1.5px dashed #3b82f6;border-radius:14px;display:flex;align-items:center;justify-content:center;gap:10px;color:#1e40af;font-weight:700;font-size:0.92rem;">
          <span style="animation:bounceDown 1s infinite alternate;display:inline-block;font-size:1.4rem;">👇</span>
          <span>તમારા ફોનની સ્ક્રીન પર નીચે બરાબર અહીં છે:</span>
          <div style="background:#ffffff;padding:6px 12px;border-radius:10px;border:1px solid #93c5fd;display:inline-flex;align-items:center;box-shadow:0 2px 6px rgba(0,0,0,0.08);">
            <svg width="20" height="23" viewBox="0 0 20 24" fill="none" stroke="#007aff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v13M5 7l5-5 5 5M3 12v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9"/></svg>
          </div>
        </div>

        <button type="button" style="width:100%;padding:13px;background:#1e3a8a;color:#fff;border:none;border-radius:14px;font-weight:800;font-size:0.98rem;cursor:pointer;box-shadow:0 4px 12px rgba(30,58,138,0.25);" onclick="document.getElementById('iosInstallModal').remove();">
          સમજાઈ ગયું (બંધ કરો)
        </button>

      </div>
    </div>

    <style>
      @keyframes fadeInIos {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }
      @keyframes bounceDown {
        from { transform: translateY(0); }
        to { transform: translateY(6px); }
      }
    </style>
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
          જો તમારા ફોનમાં એપ પહેલેથી ઉમેરેલી હોય તો હોમ સ્ક્રીન પર જુઓ.<br><br>
          અથવા Chrome માં ઉપર <b>૩ ટપકાં (⋮)</b> પર ક્લિક કરી <b>"Install app"</b> સિલેક્ટ કરો.
        </p>
        <button type="button" style="width:100%;padding:12px;background:#1e3a8a;color:#fff;border:none;border-radius:12px;font-weight:700;font-size:0.95rem;cursor:pointer;" onclick="document.getElementById('androidInstallModal').remove();">
          સમજાઈ ગયું (OK)
        </button>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', html);
}

// Ensure listeners are bound immediately
function initPwa() {
  checkInstallState();
  document.querySelectorAll('.pwa-install-trigger').forEach(btn => {
    btn.onclick = function(e) {
      e.preventDefault();
      window.triggerPwaInstall();
    };
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPwa);
} else {
  initPwa();
}

window.addEventListener('appinstalled', () => {
  console.log('PWA: Successfully installed');
  document.querySelectorAll('.pwa-install-trigger, #pwaInstallCard, #pwaInstallBtn').forEach(el => el.style.display = 'none');
});

// Register Service Worker with relative path
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/public/student/sw.js', { scope: '/public/student/' })
      .then((reg) => console.log('PWA: Service Worker Active:', reg.scope))
      .catch((err) => console.warn('PWA: SW note:', err));
  });
}
