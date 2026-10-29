import { readFileSync } from "node:fs";
export function contrast(a, b) {
    const luminance = (hex) => {
        const rgb = hex
            .match(/[a-f\d]{2}/gi)
            .map((value) => parseInt(value, 16) / 255)
            .map((value) =>
                value <= 0.04045
                    ? value / 12.92
                    : ((value + 0.055) / 1.055) ** 2.4,
            );
        return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
    };
    const first = luminance(a),
        second = luminance(b);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}
export function contrastMatrix() {
    const css = readFileSync("resources/css/tokens.css", "utf8");
    const tokens = (selector) =>
        Object.fromEntries(
            [
                ...css
                    .match(new RegExp(`${selector}\\s*\\{([^}]+)`))[1]
                    .matchAll(/--([\w-]+):\s*(#[a-f\d]{6});/gi),
            ].map((match) => [match[1], match[2]]),
        );
    const themes = {
        dark: tokens(":root"),
        light: tokens(':root\\[data-theme="light"\\]'),
    };
    return Object.entries(themes).flatMap(([theme, values]) => {
        const pairs = [];
        for (const background of [
            "bg",
            "surface",
            "surface-2",
            "surface-3",
            "hover",
            "player",
        ])
            for (const foreground of [
                "text",
                "muted",
                "active",
                "danger",
                "warning",
                "success",
                "focus",
                "control-border",
            ])
                pairs.push([
                    foreground,
                    background,
                    ["focus", "control-border"].includes(foreground) ? 3 : 4.5,
                ]);
        pairs.push(
            ["on-accent", "accent", 4.5],
            ["danger", "danger-surface", 4.5],
            ["warning", "warning-surface", 4.5],
            ["success", "success-surface", 4.5],
        );
        return pairs.map(([foreground, background, minimum]) => ({
            theme,
            foreground,
            background,
            minimum,
            ratio: contrast(values[foreground], values[background]),
        }));
    });
}
