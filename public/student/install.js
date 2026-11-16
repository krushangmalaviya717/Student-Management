// PWA Installation script
let deferredPrompt;
const installBtn = document.getElementById('pwaInstallBtn');
const installCard = document.getElementById('pwaInstallCard');

window.addEventListener('beforeinstallprompt', (e) => {
  // Prevent Chrome 67 and earlier from automatically showing the prompt
  e.preventDefault();
  // Stash the event so it can be triggered later.
  deferredPrompt = e;
  // Show the install button/card
  if (installCard) installCard.style.display = 'block';
  if (installBtn) installBtn.style.display = 'inline-flex';
});

if (installBtn) {
  installBtn.addEventListener('click', async () => {
    if (!deferredPrompt) {
      alert('એપ પહેલાથી ઇન્સ્ટોલ થયેલી છે અથવા બ્રાઉઝર PWA મોડને સપોર્ટ કરતું નથી.');
      return;
    }
    // Show the install prompt
    deferredPrompt.prompt();
    // Wait for the user to respond to the prompt
    const { outcome } = await deferredPrompt.userChoice;
    console.log(`User response to install prompt: ${outcome}`);
    // We've used the prompt, and can't use it again
    deferredPrompt = null;
    if (installCard) installCard.style.display = 'none';
    if (installBtn) installBtn.style.display = 'none';
  });
}

window.addEventListener('appinstalled', () => {
  console.log('PWA was installed');
  if (installCard) installCard.style.display = 'none';
  if (installBtn) installBtn.style.display = 'none';
});

// Register Service Worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/public/student/sw.js')
      .then((reg) => console.log('SW Registered:', reg.scope))
      .catch((err) => console.log('SW Register Failed:', err));
  });
}
