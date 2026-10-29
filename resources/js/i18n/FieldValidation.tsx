import { useEffect, useRef } from "react";
import { useLocale } from "./LocaleProvider";
import { localizeText, tr, type MessageKey } from "./runtime";
type Field = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
type ErrorEntry = {
    node: HTMLSpanElement;
    previous: string | null;
    key?: MessageKey;
    message?: string;
};
export function FieldValidation() {
    const { locale } = useLocale();
    const fields = useRef(new Map<Field, ErrorEntry>());
    useEffect(() => {
        for (const [target, entry] of fields.current) {
            const message = entry.key
                ? tr(entry.key)
                : localizeText(entry.message || "");
            entry.node.textContent = message;
            target.setCustomValidity(message);
        }
    }, [locale]);
    useEffect(() => {
        let sequence = 0;
        const isField = (target: EventTarget | null): target is Field =>
            target instanceof HTMLInputElement ||
            target instanceof HTMLTextAreaElement ||
            target instanceof HTMLSelectElement;
        const clear = (target: EventTarget | null) => {
            if (!isField(target)) return;
            target.setCustomValidity("");
            target.removeAttribute("aria-invalid");
            const entry = fields.current.get(target);
            if (!entry) return;
            entry.node.remove();
            if (entry.previous)
                target.setAttribute("aria-describedby", entry.previous);
            else target.removeAttribute("aria-describedby");
            fields.current.delete(target);
        };
        const show = (
            target: Field,
            source: { key?: MessageKey; message?: string },
        ) => {
            clear(target);
            const message = source.key
                ? tr(source.key)
                : localizeText(source.message || "");
            target.setCustomValidity(message);
            target.setAttribute("aria-invalid", "true");
            const node = document.createElement("span");
            node.className = "field-error";
            node.id = `ak-field-${++sequence}`;
            node.setAttribute("role", "alert");
            node.textContent = message;
            const previous = target.getAttribute("aria-describedby");
            target.setAttribute(
                "aria-describedby",
                [previous, node.id].filter(Boolean).join(" "),
            );
            (target.closest("label") || target).insertAdjacentElement(
                "afterend",
                node,
            );
            fields.current.set(target, { node, previous, ...source });
        };
        const invalid = (event: Event) => {
            const target = event.target;
            if (!isField(target)) return;
            target.setCustomValidity("");
            show(target, {
                key: target.validity.valueMissing
                    ? "field.required"
                    : target.validity.typeMismatch && target.type === "email"
                      ? "field.email"
                      : "field.invalid",
            });
        };
        const server = (event: Event) => {
            const errors = (event as CustomEvent<Record<string, string[]>>)
                .detail;
            const dialogs = document.querySelectorAll(".dialog-content");
            const root = dialogs.length
                ? dialogs[dialogs.length - 1]
                : document;
            for (const [name, messages] of Object.entries(errors)) {
                const target = [
                    ...root.querySelectorAll<
                        | HTMLInputElement
                        | HTMLSelectElement
                        | HTMLTextAreaElement
                    >("input[name],select[name],textarea[name]"),
                ].find(
                    (field) =>
                        field.name === name &&
                        field.getClientRects().length > 0,
                );
                if (target && messages[0])
                    show(target, { message: messages[0] });
            }
        };
        const input = (event: Event) => clear(event.target);
        document.addEventListener("invalid", invalid, true);
        document.addEventListener("input", input);
        document.addEventListener("change", input);
        window.addEventListener("api-field-errors", server);
        return () => {
            document.removeEventListener("invalid", invalid, true);
            document.removeEventListener("input", input);
            document.removeEventListener("change", input);
            window.removeEventListener("api-field-errors", server);
            for (const target of fields.current.keys()) clear(target);
        };
    }, []);
    return null;
}
