(() => {
    function applyTheme(themeColor) {
        if (typeof themeColor !== 'string' || !/^#[0-9a-f]{6}$/i.test(themeColor)) {
            return false;
        }

        const normalizedColor = themeColor.toLowerCase();
        const red = Number.parseInt(normalizedColor.slice(1, 3), 16);
        const green = Number.parseInt(normalizedColor.slice(3, 5), 16);
        const blue = Number.parseInt(normalizedColor.slice(5, 7), 16);
        const root = document.documentElement;

        root.style.setProperty('--ims-primary', normalizedColor);
        root.style.setProperty('--ims-primary-rgb', `${red}, ${green}, ${blue}`);
        root.style.setProperty('--bs-primary', normalizedColor);
        root.style.setProperty('--bs-primary-rgb', `${red}, ${green}, ${blue}`);
        root.style.setProperty('--bs-focus-ring-color', `rgba(${red}, ${green}, ${blue}, 0.25)`);
        return true;
    }

    window.applyImsTheme = (themeColor) => {
        if (!applyTheme(themeColor)) {
            return false;
        }

        try {
            window.localStorage.setItem('ims.theme-color', themeColor.toLowerCase());
        } catch {}

        return true;
    };

    try {
        const cachedThemeColor = window.localStorage.getItem('ims.theme-color');
        if (cachedThemeColor) {
            window.applyImsTheme(cachedThemeColor);
        }
    } catch {}
})();