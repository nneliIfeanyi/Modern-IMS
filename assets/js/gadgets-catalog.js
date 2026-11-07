const API_URL = 'public/api/gadget-catalog';

const els = {
    grid: document.getElementById('gadget-catalogue-grid'),
    message: document.getElementById('gadget-catalogue-message'),
    search: document.getElementById('gadget-search'),
    category: document.getElementById('gadget-category-filter'),
};

let products = [];

function showMessage(message, tone = '') {
    els.message.textContent = message;
    els.message.className = `gadget-catalogue-message${tone ? ` ${tone}` : ''}`;
    els.message.hidden = false;
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value));
}

function createProductCard(product) {
    const column = document.createElement('div');
    const card = document.createElement('article');
    const imageWrap = document.createElement('div');
    const image = document.createElement('img');
    const body = document.createElement('div');
    const category = document.createElement('small');
    const name = document.createElement('h3');
    const meta = document.createElement('p');
    const price = document.createElement('p');
    const button = document.createElement('button');
    const icon = document.createElement('i');

    column.className = 'col-sm-6 col-lg-3';
    card.className = 'product-card gadgets-product-card';
    imageWrap.className = 'gadgets-product-image';
    image.src = product.image_path;
    image.alt = product.name;
    image.loading = 'lazy';
    imageWrap.append(image);
    body.className = 'product-body';
    category.textContent = product.category;
    name.textContent = product.name;
    const modelDescription = [product.brand, product.model].filter(Boolean).join(' ');
    meta.textContent = product.description;
    if (modelDescription) {
        const detail = document.createElement('small');
        detail.className = 'd-block mt-1 text-muted';
        detail.textContent = modelDescription;
        meta.append(document.createElement('br'), detail);
    }
    price.className = 'gadget-product-price';
    price.textContent = formatPrice(product.display_price) || 'Ask for current price';
    button.className = 'btn btn-outline-primary w-100 add-product';
    button.type = 'button';
    button.dataset.product = product.name;
    button.textContent = 'Add to enquiry ';
    icon.className = 'bi bi-plus-lg';
    button.append(icon);
    body.append(category, name, meta, price, button);
    card.append(imageWrap, body);
    column.append(card);

    return column;
}

function renderProducts() {
    const query = els.search.value.trim().toLocaleLowerCase();
    const category = els.category.value;
    const visibleProducts = products.filter((product) => {
        const searchable = [product.name, product.category, product.brand, product.model, product.description]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase();
        return (!category || product.category === category) && (!query || searchable.includes(query));
    });

    els.grid.replaceChildren(...visibleProducts.map(createProductCard));
    if (visibleProducts.length === 0) {
        showMessage(products.length === 0
            ? 'There are no featured products right now. Please contact us about what you need.'
            : 'No products match your search. Try another category or search term.');
    } else {
        els.message.hidden = true;
    }
}

async function loadProducts() {
    try {
        const response = await fetch(API_URL, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
            throw new Error(payload.message || 'The gadget catalogue could not be loaded.');
        }

        products = Array.isArray(payload.data) ? payload.data : [];
        renderProducts();
    } catch (error) {
        showMessage(error.message || 'The gadget catalogue could not be loaded. Please try again later.', 'is-error');
    }
}

els.search.addEventListener('input', renderProducts);
els.category.addEventListener('change', renderProducts);
document.querySelectorAll('[data-gadget-filter]').forEach((link) => {
    link.addEventListener('click', () => {
        els.category.value = link.dataset.gadgetFilter || '';
        renderProducts();
    });
});

loadProducts();
