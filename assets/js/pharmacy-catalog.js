const API_URL = 'public/api/pharmacy-catalog';

const elements = {
    grid: document.getElementById('pharmacy-catalogue-grid'),
    message: document.getElementById('pharmacy-catalogue-message'),
    search: document.getElementById('pharmacy-search'),
    category: document.getElementById('pharmacy-category-filter'),
};

let products = [];

function showMessage(message, isError = false) {
    elements.message.textContent = message;
    elements.message.className = `catalogue-message${isError ? ' is-error' : ''}`;
    elements.message.hidden = false;
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return 'Ask for current price';
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
    const category = document.createElement('span');
    const body = document.createElement('div');
    const brand = document.createElement('small');
    const name = document.createElement('h3');
    const variant = document.createElement('small');
    const description = document.createElement('p');
    const price = document.createElement('p');
    const button = document.createElement('button');

    column.className = 'col-sm-6 col-lg-3';
    card.className = 'product-card pharmacy-catalog-card';
    imageWrap.className = 'product-image pharmacy-catalog-image';
    image.src = product.image_path;
    image.alt = product.name;
    image.loading = 'lazy';
    category.textContent = product.category;
    imageWrap.append(image, category);

    body.className = 'product-body';
    brand.textContent = product.label || product.brand || product.category;
    name.textContent = product.name;
    variant.className = 'pharmacy-product-variant';
    variant.textContent = [product.brand, product.variant].filter(Boolean).join(' · ');
    description.textContent = product.description;
    price.className = 'catalog-product-price';
    price.textContent = formatPrice(product.display_price);
    button.className = 'btn btn-outline-primary w-100 add-product';
    button.type = 'button';
    button.dataset.product = product.name;
    button.textContent = 'Add to order';

    body.append(brand, name);
    if (variant.textContent) {
        body.append(variant);
    }
    body.append(description, price, button);
    card.append(imageWrap, body);
    column.append(card);

    return column;
}

function renderProducts() {
    const searchTerm = elements.search.value.trim().toLocaleLowerCase();
    const category = elements.category.value;
    const visibleProducts = products.filter((product) => {
        const searchable = [product.name, product.category, product.label, product.brand, product.variant, product.description]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase();

        return (!category || product.category === category) && (!searchTerm || searchable.includes(searchTerm));
    });

    elements.grid.replaceChildren(...visibleProducts.map(createProductCard));
    if (visibleProducts.length === 0) {
        showMessage(products.length === 0
            ? 'There are no featured products right now. Please contact the store about what you need.'
            : 'No products match your search. Try another category or search term.');
    } else {
        elements.message.hidden = true;
    }
}

async function loadProducts() {
    try {
        const response = await fetch(API_URL, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
            throw new Error(payload.message || 'The pharmacy catalogue could not be loaded.');
        }

        products = Array.isArray(payload.data) ? payload.data : [];
        renderProducts();
    } catch (error) {
        showMessage(error.message || 'The pharmacy catalogue could not be loaded. Please try again later.', true);
    }
}

elements.search.addEventListener('input', renderProducts);
elements.category.addEventListener('change', renderProducts);
loadProducts();
