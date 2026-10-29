import {
    DraftScope,
    useDraftGuard,
    useDraftNavigation,
} from "../features/drafts/DraftNavigation";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import {
    useEffect,
    useState,
    useRef,
    useId,
    type ButtonHTMLAttributes,
    type Ref,
    type CSSProperties,
    type PropsWithChildren,
} from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { ArrowLeft, Disc3, LoaderCircle, TriangleAlert, X } from "lucide-react";
import { Link } from "react-router-dom";

export function Brand({ small = false }: { small?: boolean }) {
    useLocale();
    return (
        <Link
            to="/"
            className={`brand ${small ? "brand-small" : ""}`}
            aria-label={tr("m652478c975")}
        >
            <span className="brand-mark" aria-hidden="true">
                <i />
                <i />
                <i />
                <i />
            </span>
            <span>
                {tr("mb39fa3f0f8")}
                <small lang="en" dir="ltr">
                    AHANG KHATKESH
                </small>
            </span>
        </Link>
    );
}
export function IconButton({
    label,
    children,
    className = "",
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    label: string;
    ref?: Ref<HTMLButtonElement>;
}) {
    useLocale();
    return (
        <button
            type="button"
            className={`icon-button ${className}`}
            aria-label={label}
            title={label}
            {...props}
        >
            {children}
        </button>
    );
}
export function Dialog({
    open,
    onOpenChange,
    title,
    description,
    priority = false,
    guard = true,
    dirty = false,
    onDiscard,
    className = "",
    children,
}: PropsWithChildren<{
    open: boolean;
    onOpenChange: (value: boolean) => void;
    title: string;
    description: string;
    priority?: boolean;
    guard?: boolean;
    dirty?: boolean;
    onDiscard?: () => void;
    className?: string;
}>) {
    useLocale();
    const { dir } = useLocale();
    const scope = useId();
    const navigation = useDraftNavigation();
    useDraftGuard(open && dirty, onDiscard, scope);
    const changeOpen = (value: boolean) => {
        if (value || !guard) onOpenChange(value);
        else
            navigation.request({
                scope,
                proceed: () => onOpenChange(false),
                cancel: () => {},
            });
    };
    const content = useRef<HTMLDivElement>(null);
    const opener = useRef<HTMLElement | null>(null);
    return (
        <DialogPrimitive.Root open={open} onOpenChange={changeOpen}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay
                    className={`dialog-overlay ${priority ? "dialog-priority" : ""}`}
                />
                <DialogPrimitive.Content
                    ref={content}
                    className={`dialog-content ${className} ${priority ? "dialog-priority" : ""}`}
                    dir={dir}
                    onCloseAutoFocus={(event) => {
                        if (!priority && opener.current?.isConnected) {
                            event.preventDefault();
                            opener.current.focus();
                        }
                        if (priority) {
                            const underlying =
                                document.querySelector<HTMLInputElement>(
                                    ".dialog-content:not(.dialog-priority) input:not([disabled]), .dialog-content:not(.dialog-priority) button:not([disabled])",
                                );
                            if (underlying) {
                                event.preventDefault();
                                underlying.focus();
                            } else if (opener.current?.isConnected) {
                                event.preventDefault();
                                opener.current.focus();
                            }
                        }
                    }}
                    onOpenAutoFocus={(event) => {
                        opener.current =
                            document.activeElement instanceof HTMLElement
                                ? document.activeElement
                                : null;
                        const field =
                            content.current?.querySelector<HTMLInputElement>(
                                "input:not([disabled]), textarea:not([disabled]), select:not([disabled])",
                            );
                        if (field) {
                            event.preventDefault();
                            field.focus();
                        }
                    }}
                >
                    <DialogPrimitive.Title>{title}</DialogPrimitive.Title>
                    <DialogPrimitive.Description>
                        {description}
                    </DialogPrimitive.Description>
                    <DialogPrimitive.Close asChild>
                        <IconButton
                            className="dialog-close"
                            label={tr("m188901d16c")}
                        >
                            <X size={18} />
                        </IconButton>
                    </DialogPrimitive.Close>
                    <DraftScope.Provider value={scope}>
                        {children}
                    </DraftScope.Provider>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
const palettes = [
    ["#282c4a", "#f3aa82", "#7d8bba"],
    ["#374b38", "#d9e89c", "#a3b77d"],
    ["#b33e2f", "#ffdf9e", "#f7a860"],
    ["#92b3ad", "#244b4b", "#d9e8cb"],
    ["#cc9e8f", "#6e3e42", "#f4dcbb"],
    ["#313f65", "#d5c8ed", "#798cb5"],
];
export function Artwork({
    seed = 0,
    url,
    className = "",
    label = "",
}: {
    seed?: number;
    url?: string | null;
    className?: string;
    label?: string;
}) {
    useLocale();
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [url]);
    const variant = Math.abs(seed) % palettes.length;
    const [bg, accent, secondary] = palettes[variant];
    return (
        <div
            className={`artwork art-${variant} ${className}`}
            style={{ "--art-bg": bg, "--art-accent": accent } as CSSProperties}
        >
            {url && !failed ? (
                <img
                    src={url}
                    alt={label}
                    loading="lazy"
                    onError={() => setFailed(true)}
                />
            ) : (
                <>
                    <svg viewBox="0 0 400 400" aria-hidden="true">
                        <rect width="400" height="400" fill={bg} />
                        {variant % 3 === 0 ? (
                            <g>
                                {Array.from({ length: 16 }, (_, i) => (
                                    <ellipse
                                        key={i}
                                        cx="230"
                                        cy="190"
                                        rx={50 + i * 11}
                                        ry={20 + i * 10}
                                        fill="none"
                                        stroke={
                                            i % 3 === 0 ? accent : secondary
                                        }
                                        strokeWidth="2"
                                        transform="rotate(-33 230 190)"
                                    />
                                ))}
                                <circle
                                    cx="230"
                                    cy="190"
                                    r="11"
                                    fill={accent}
                                />
                            </g>
                        ) : variant % 3 === 1 ? (
                            <g>
                                {Array.from({ length: 9 }, (_, i) => (
                                    <path
                                        key={i}
                                        d={`M ${-80 + i * 23} 310 Q ${210 + i * 12} ${-70 + i * 17} ${500 + i * 12} 140`}
                                        fill="none"
                                        stroke={i % 2 ? secondary : accent}
                                        strokeWidth="13"
                                    />
                                ))}
                                <circle cx="90" cy="105" r="30" fill={accent} />
                            </g>
                        ) : (
                            <g>
                                {Array.from({ length: 7 }, (_, i) => (
                                    <rect
                                        key={i}
                                        x={60 + i * 23}
                                        y={65 + i * 11}
                                        width="160"
                                        height="210"
                                        rx="80"
                                        fill={i % 2 ? bg : accent}
                                        stroke={secondary}
                                        strokeWidth="1"
                                        transform={`rotate(${i * 6 - 24} 200 200)`}
                                    />
                                ))}
                            </g>
                        )}
                        <path
                            d="M25 28h50M25 28v20M375 350v23h-50"
                            fill="none"
                            stroke={accent}
                            strokeWidth="2"
                            opacity=".7"
                        />
                    </svg>
                    <span className="art-number" lang="en">
                        AK — {String(variant + 1).padStart(2, "0")}
                    </span>
                    <span className="art-caption" lang="en">
                        SOUND
                        <br />
                        IN GOOD MEASURE.
                    </span>
                </>
            )}
        </div>
    );
}
export function Loading({ label = tr("m441def3dc4") }: { label?: string }) {
    useLocale();
    return (
        <div
            className="loading state-panel state-loading"
            role="status"
            aria-busy="true"
        >
            <span className="state-symbol" aria-hidden="true">
                <LoaderCircle className="spin" size={26} />
            </span>
            <span>{label}</span>
        </div>
    );
}
export function ErrorState({
    error,
    retry,
}: {
    error: Error;
    retry?: () => void;
}) {
    useLocale();
    return (
        <div className="empty-state state-panel state-error" role="alert">
            <span className="state-symbol" aria-hidden="true">
                <TriangleAlert size={28} />
            </span>
            <h2>{tr("mf4a3ad1e66")}</h2>
            <p>{error.message}</p>
            {retry && (
                <button className="button secondary" onClick={retry}>
                    {tr("med6ee8fe9d")}
                </button>
            )}
        </div>
    );
}
export function EmptyState({
    title,
    text,
    admin = false,
}: {
    title: string;
    text: string;
    admin?: boolean;
}) {
    useLocale();
    return (
        <div className="empty-state state-panel state-empty">
            <span className="state-symbol" aria-hidden="true">
                <Disc3 size={32} strokeWidth={1.5} />
            </span>
            <h2>{title}</h2>
            <p>{text}</p>
            {admin && (
                <Link to="/admin" className="button">
                    {tr("m74de17b78f")}
                    <ArrowLeft size={16} />
                </Link>
            )}
        </div>
    );
}
