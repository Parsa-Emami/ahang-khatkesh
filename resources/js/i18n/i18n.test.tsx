import { currentPreferences, subscribePreferences } from "./runtime";
import { afterEach, describe, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parse } from "@formatjs/icu-messageformat-parser";
import fa from "./messages/fa-IR.json";
import en from "./messages/en-US.json";
import {
    asciiDigits,
    number,
    date,
    roleLabel,
    setRuntimePreferences,
    tr,
    localizeText,
    type MessageKey,
} from "./runtime";
import { defaults, normalizePreferences, resolveDirection } from "./locales";
import { Artwork, IconButton, Loading } from "../components/UI";
import { api } from "../lib/api";
afterEach(() => {
    setRuntimePreferences(defaults);
    vi.unstubAllGlobals();
});
describe("locale direction theme matrix", () => {
    it("has complete typed catalogs and valid ICU templates without an English fallback to Persian", () => {
        expect(Object.keys(en).sort()).toEqual(Object.keys(fa).sort());
        for (const value of Object.values(en)) {
            expect(value.trim()).not.toBe("");
            expect(value).not.toMatch(/[\u0600-\u06ff]/);
            expect(() => parse(value)).not.toThrow();
        }
        for (const value of Object.values(fa))
            expect(() => parse(value)).not.toThrow();
        for (const key of Object.keys(fa) as MessageKey[]) {
            setRuntimePreferences({ ...defaults, locale: "en-US" });
            const output = tr(key, {
                v0: 3,
                v1: "Example",
                v2: "",
                count: 3,
                time: "12:00",
                title: "Example",
            });
            expect(output).not.toBe(key);
            expect(output).not.toMatch(/\{v\d+\}/);
        }
    });
    it("publishes one stable locale snapshot before transport or rendering reads it", () => {
        const listener = vi.fn();
        const unsubscribe = subscribePreferences(listener);
        try {
            const next = { ...defaults, locale: "en-US" as const };
            setRuntimePreferences(next);
            expect(currentPreferences()).toBe(next);
            expect(tr("settings.language")).toBe("Language");
            setRuntimePreferences(next);
            expect(listener).toHaveBeenCalledTimes(1);
        } finally {
            unsubscribe();
        }
    });
    it("keeps language and explicit direction independent and rejects unknown stored settings", () => {
        expect(resolveDirection({ ...defaults, locale: "en-US" })).toBe("ltr");
        expect(
            resolveDirection({
                ...defaults,
                locale: "en-US",
                direction: "rtl",
            }),
        ).toBe("rtl");
        expect(resolveDirection({ ...defaults, direction: "ltr" })).toBe("ltr");
        expect(
            normalizePreferences({
                locale: "xx" as never,
                direction: "bad" as never,
                motion: "bad" as never,
                time_zone: "../wrong",
            }),
        ).toEqual(defaults);
    });
    it("formats numbers dates and roles using the active locale and time zone", () => {
        setRuntimePreferences({
            ...defaults,
            locale: "en-US",
            time_zone: "UTC",
        });
        expect(number(1234)).toBe("1,234");
        expect(
            date("2026-10-29T12:00:00Z", {
                hour: "2-digit",
                minute: "2-digit",
                hour12: false,
            }),
        ).toBe("12:00");
        expect(roleLabel("owner")).toBe("Super administrator");
        expect(asciiDigits("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩")).toBe(
            "01234567890123456789",
        );
        setRuntimePreferences(defaults);
        expect(number(1234)).toBe("۱٬۲۳۴");
    });
    it("renders existing primitives with translated accessible names and safe user text", () => {
        setRuntimePreferences({ ...defaults, locale: "en-US" });
        expect(renderToStaticMarkup(<Loading />)).toContain("Loading…");
        expect(
            renderToStaticMarkup(
                <IconButton label={tr("draft.stay")}>↶</IconButton>,
            ),
        ).toContain('aria-label="Continue editing"');
        const html = renderToStaticMarkup(
            <Artwork url="/cover" label={'<script>alert("x")</script>'} />,
        );
        expect(html).not.toContain("<script>");
        expect(html).toContain("&lt;script&gt;");
        const source =
            fa[
                Object.keys(fa).find(
                    (key) => fa[key as MessageKey] === "عملیات انجام نشد.",
                ) as MessageKey
            ];
        expect(localizeText(source)).toBe(
            "The operation could not be completed.",
        );
    });
    it("sends the explicit allowlisted language to the same-origin API", async () => {
        setRuntimePreferences({ ...defaults, locale: "en-US" });
        const fetcher = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: [] }), {
                headers: { "Content-Type": "application/json" },
            }),
        );
        vi.stubGlobal("fetch", fetcher);
        await api("/stats");
        expect(fetcher).toHaveBeenCalledWith(
            "/api/v1/stats",
            expect.objectContaining({ credentials: "same-origin" }),
        );
        expect(
            new Headers(fetcher.mock.calls[0][1].headers).get(
                "Accept-Language",
            ),
        ).toBe("en-US");
    });
});
