function buildConfirmationModal(options) {
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" data-confirm-action></button>
                </div>
            </div>
        </div>`;

    modal.querySelector('.modal-title').textContent = options.title || 'Confirm action';
    modal.querySelector('.modal-body').textContent = options.message || 'Are you sure you want to continue?';

    const actionButton = modal.querySelector('[data-confirm-action]');
    actionButton.classList.add(`btn-${options.tone || 'danger'}`);
    actionButton.textContent = options.confirmLabel || 'Confirm';

    return modal;
}

export function confirmAction(options = {}) {
    return new Promise((resolve) => {
        const modal = buildConfirmationModal(options);
        document.body.appendChild(modal);
        const instance = new bootstrap.Modal(modal, { backdrop: 'static' });
        let settled = false;

        const finish = (confirmed) => {
            if (settled) {
                return;
            }

            settled = true;
            resolve(confirmed);
            instance.hide();
        };

        modal.querySelector('[data-confirm-action]').addEventListener('click', () => finish(true));
        modal.addEventListener('hidden.bs.modal', () => {
            if (!settled) {
                settled = true;
                resolve(false);
            }
            modal.remove();
        }, { once: true });

        instance.show();
    });
}