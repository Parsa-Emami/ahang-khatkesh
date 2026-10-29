(function () {
    try {
        var value = JSON.parse(localStorage.getItem("ak-preferences") || "{}");
        var locale = value.locale === "en-US" ? "en-US" : "fa-IR";
        document.documentElement.lang = locale;
        document.documentElement.dir = value.direction === "ltr" || value.direction === "rtl" ? value.direction : locale === "fa-IR" ? "rtl" : "ltr";
        document.documentElement.dataset.motion = value.motion === "reduced" || value.motion === "full" ? value.motion : "system";
        var theme = localStorage.getItem("ak-theme");
        document.documentElement.dataset.theme = theme === "light" || theme === "dark" ? theme : matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    } catch (error) {}
})();
