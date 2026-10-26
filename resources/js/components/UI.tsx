import {
    useEffect,
    useState,
    type ButtonHTMLAttributes,
    type CSSProperties,
    type PropsWithChildren,
} from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { ArrowLeft, Disc3, LoaderCircle, X } from "lucide-react";
import { Link } from "react-router-dom";

export function Brand({ small = false }: { small?: boolean }) {
    return (
        <Link
            to="/"
            className={`brand ${small ? "brand-small" : ""}`}
            aria-label="آهنگ خط‌کش، خانه"
        >
            <span className="brand-mark" aria-hidden="true">
                <i />
                <i />
                <i />
                <i />
            </span>
            <span>
                آهنگ خط‌کش
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
}: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
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
    children,
}: PropsWithChildren<{
    open: boolean;
    onOpenChange: (value: boolean) => void;
    title: string;
    description: string;
}>) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="dialog-overlay" />
                <DialogPrimitive.Content className="dialog-content" dir="rtl">
                    <DialogPrimitive.Title>{title}</DialogPrimitive.Title>
                    <DialogPrimitive.Description>
                        {description}
                    </DialogPrimitive.Description>
                    <DialogPrimitive.Close asChild>
                        <IconButton className="dialog-close" label="بستن پنجره">
                            <X size={18} />
                        </IconButton>
                    </DialogPrimitive.Close>
                    {children}
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
export function Loading({ label = "در حال دریافت…" }: { label?: string }) {
    return (
        <div className="loading" role="status">
            <LoaderCircle className="spin" size={22} />
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
    return (
        <div className="empty-state" role="alert">
            <Disc3 size={32} />
            <h2>دریافت اطلاعات انجام نشد</h2>
            <p>{error.message}</p>
            {retry && (
                <button className="button secondary" onClick={retry}>
                    تلاش دوباره
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
    return (
        <div className="empty-state">
            <Disc3 size={42} strokeWidth={1} />
            <h2>{title}</h2>
            <p>{text}</p>
            {admin && (
                <Link to="/admin" className="button">
                    ورود اولین پلی‌لیست <ArrowLeft size={16} />
                </Link>
            )}
        </div>
    );
}
