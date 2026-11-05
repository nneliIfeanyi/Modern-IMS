(() => {
    let deferredInstallPrompt = null;
    let isInstalled = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    const showInstallInstructions = () => {
        let dialog = document.getElementById('pwa-install-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'pwa-install-dialog';
            dialog.className = 'pwa-install-dialog';
            dialog.setAttribute('aria-labelledby', 'pwa-install-title');
            dialog.innerHTML = `
                <h2 id="pwa-install-title">Install STANVIC IMS</h2>
                <p>Use your browser menu to install this app or add it to your home screen.</p>
                <ul>
                    <li>On Chrome or Edge, choose <strong>Install app</strong> from the browser menu.</li>
                    <li>On iPhone or iPad, open the Share menu and choose <strong>Add to Home Screen</strong>.</li>
                </ul>
                <form method="dialog"><button class="btn btn-primary" type="submit">Got it</button></form>
            `;
            document.body.append(dialog);
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    };

    const syncInstallButtons = () => {
        document.querySelectorAll('[data-pwa-install]').forEach((button) => {
            button.hidden = isInstalled;

            if (button.dataset.pwaBound) {
                return;
            }

            button.dataset.pwaBound = 'true';
            button.addEventListener('click', async () => {
                if (!deferredInstallPrompt) {
                    showInstallInstructions();
                    return;
                }

                const installPrompt = deferredInstallPrompt;
                deferredInstallPrompt = null;
                syncInstallButtons();

                try {
                    await installPrompt.prompt();
                    const choice = await installPrompt.userChoice;
                    isInstalled = choice.outcome === 'accepted';
                } catch (error) {
                    console.error('Unable to open the app installation prompt.', error);
                }

                syncInstallButtons();
            });
        });
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        syncInstallButtons();
    });

    window.addEventListener('appinstalled', () => {
        isInstalled = true;
        deferredInstallPrompt = null;
        syncInstallButtons();
    });

    document.addEventListener('DOMContentLoaded', () => {
        const installButtonObserver = new MutationObserver(syncInstallButtons);
        installButtonObserver.observe(document.body, { childList: true, subtree: true });
        syncInstallButtons();
    });

    window.addEventListener('load', () => {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        navigator.serviceWorker.register('./service-worker.js')
            .catch((error) => console.error('Unable to register the app service worker.', error));
    });
})();
