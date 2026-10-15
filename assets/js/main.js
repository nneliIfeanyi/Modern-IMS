(() => {
    const config = window.STORE_CONFIG || {};
    const orderText = document.querySelector('#orderText');
    const form = document.querySelector('#orderForm');
    const alertBox = document.querySelector('#formAlert');
    const fulfilment = document.querySelector('#fulfilment');
    const addressWrap = document.querySelector('#addressWrap');
    const addressInput = document.querySelector('#deliveryAddress');
    const draftKey = 'chypaul.orderDraft';
    const legacyDraftKey = 'medimart.orderDraft';

    const setText = (key, value) => document.querySelectorAll(`[data-store="${key}"]`).forEach((node) => {
        node.textContent = value;
    });

    setText('name', config.name || 'Your Store');
    setText('phone', config.phone || 'Phone number to be confirmed');
    setText('address', config.address || 'Store address to be confirmed');
    setText('hours', config.hours || 'Opening hours to be confirmed');
    document.querySelectorAll('[data-store-link="phone"]').forEach((link) => link.href = `tel:${(config.phone || '').replace(/[^+\d]/g, '')}`);
    document.querySelectorAll('[data-store-link="maps"]').forEach((link) => link.href = config.mapsUrl || '#');
    ['instagram', 'facebook', 'whatsapp'].forEach((network) => document.querySelectorAll(`[data-store-link="${network}"]`).forEach((link) => link.href = config.social?.[network] || '#'));
    document.querySelectorAll('[data-store="name"]').forEach((node) => node.textContent = config.name || 'Your Store');
    document.title = `${config.name || 'Your Store'} | Pharmacy & Supermarket`;
    document.querySelector('#copyrightYear').textContent = new Date().getFullYear();

    const heroImage = document.querySelector('.hero-visual img');
    const heroSlides = [
        {
            src: 'assets/img/hero1.jpg',
            alt: 'Pharmacist arranging healthcare products on a shelf'
        },
        {
            src: 'assets/img/hero5.jpg',
            alt: 'Bright pharmacy shelves stocked with healthcare products'
        },
        {
            src: 'assets/img/hero2.jpg',
            alt: 'Healthcare professional standing in a welcoming pharmacy'
        }
    ];

    if (heroImage) {
        let slideIndex = 0;
        heroImage.src = heroSlides[0].src;
        heroImage.alt = heroSlides[0].alt;
        heroSlides.slice(1).forEach((slide) => {
            const image = new Image();
            image.src = slide.src;
        });

        const changeHeroSlide = async () => {
            const nextIndex = (slideIndex + 1) % heroSlides.length;
            const nextSlide = heroSlides[nextIndex];
            const nextImage = new Image();
            nextImage.src = nextSlide.src;
            try { await nextImage.decode(); } catch (error) { /* The browser can still display the image. */ }
            heroImage.classList.add('is-changing');
            window.setTimeout(() => {
                slideIndex = nextIndex;
                heroImage.src = heroSlides[slideIndex].src;
                heroImage.alt = heroSlides[slideIndex].alt;
                window.requestAnimationFrame(() => heroImage.classList.remove('is-changing'));
            }, 800);
        };

        window.setInterval(changeHeroSlide, 5000);
    }

    async function loadFeaturedItems() {
        const featuredGrid = document.querySelector('.featured-section .row');
        if (!featuredGrid) return;

        try {
            const response = await fetch('public/featured.php', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Featured items could not be loaded.');
            const payload = await response.json();
            featuredGrid.replaceChildren();

            (payload.items || []).forEach((item) => {
                const column = document.createElement('div');
                column.className = 'col-sm-6 col-lg-3';
                column.innerHTML = '<article class="product-card"><div class="product-image"><span></span></div><div class="product-body"><small></small><h3></h3><p></p><button class="btn btn-outline-primary w-100 add-product" type="button">Add to order</button></div></article>';
                const image = column.querySelector('.product-image');
                image.style.backgroundImage = `linear-gradient(rgba(255,255,255,.18), rgba(255,255,255,.18)), url("${item.image}")`;
                image.style.backgroundSize = 'cover';
                image.style.backgroundPosition = 'center';
                column.querySelector('.product-image span').textContent = item.category || '';
                column.querySelector('.product-body small').textContent = item.label || '';
                column.querySelector('.product-body h3').textContent = item.title || '';
                column.querySelector('.product-body p').textContent = item.description || '';
                column.querySelector('.add-product').dataset.product = item.title || '';
                featuredGrid.appendChild(column);
            });
        } catch (error) {
            featuredGrid.replaceChildren();
        }
    }

    loadFeaturedItems();

    try {
        const savedDraft = localStorage.getItem(draftKey) || localStorage.getItem(legacyDraftKey) || '';
        orderText.value = savedDraft;
        if (savedDraft && !localStorage.getItem(draftKey)) localStorage.setItem(draftKey, savedDraft);
    } catch (error) {
        // The form remains usable when storage is unavailable.
    }

    orderText.addEventListener('input', () => {
        try { localStorage.setItem(draftKey, orderText.value); } catch (error) { /* Ignore storage errors. */ }
    });

    fulfilment.addEventListener('change', () => {
        const delivery = fulfilment.value === 'Delivery';
        addressWrap.hidden = !delivery;
        addressInput.required = delivery;
        if (!delivery) addressInput.value = '';
    });

    document.querySelector('.featured-section .row')?.addEventListener('click', (event) => {
        const button = event.target.closest('.add-product');
        if (!button) return;
        const product = button.dataset.product;
        const addition = `${orderText.value.trim() ? '\n' : ''}- ${product} — quantity: `;
        orderText.value += addition;
        orderText.dispatchEvent(new Event('input'));
        orderText.focus();
        document.querySelector('#order').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    document.querySelectorAll('[data-category]').forEach((button) => button.addEventListener('click', () => {
        const category = button.dataset.category;
        const addition = `${orderText.value.trim() ? '\n' : ''}Category enquiry: ${category}\nItems: `;
        orderText.value += addition;
        orderText.dispatchEvent(new Event('input'));
        orderText.focus();
        document.querySelector('#order').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const order = orderText.value.trim();
        const delivery = fulfilment.value === 'Delivery';
        alertBox.className = 'form-alert';
        alertBox.textContent = '';
        if (!order) {
            alertBox.className = 'form-alert is-error';
            alertBox.textContent = 'Please add at least one item or question to your order.';
            orderText.focus();
            return;
        }
        if (delivery && !addressInput.value.trim()) {
            alertBox.className = 'form-alert is-error';
            alertBox.textContent = 'Please add a delivery address, or choose pickup.';
            addressInput.focus();
            return;
        }
        const number = String(config.whatsappNumber || '').replace(/\D/g, '');
        if (!number || number === '2340000000000') {
            alertBox.className = 'form-alert is-error';
            alertBox.textContent = 'WhatsApp is still being configured. Please add the store number in assets/js/config.js.';
            return;
        }
        const name = document.querySelector('#customerName').value.trim();
        const lines = ['NEW CUSTOMER ORDER', `Store: ${config.name || 'Your Store'}`, '', `Customer name: ${name || 'Not supplied'}`, 'Order:', order, '', `Fulfilment preference: ${fulfilment.value}`];
        if (delivery) lines.push(`Delivery address: ${addressInput.value.trim()}`);
        lines.push('', 'Please confirm product availability, current prices and the total amount payable.', '', 'This is an order enquiry, not a confirmed purchase.');
        const url = `https://wa.me/${number}?text=${encodeURIComponent(lines.join('\n'))}`;
        alertBox.className = 'form-alert is-success';
        alertBox.textContent = 'WhatsApp is opening so you can review and send your message.';
        window.open(url, '_blank', 'noopener,noreferrer');
    });

    const toggler = document.querySelector('.navbar-toggler');
    const nav = document.querySelector('#mainNav');
    toggler.addEventListener('click', () => {
        const open = nav.classList.toggle('show');
        toggler.setAttribute('aria-expanded', String(open));
    });
    nav.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
        nav.classList.remove('show');
        toggler.setAttribute('aria-expanded', 'false');
    }));
})();
