import { useLocale } from "../i18n/LocaleProvider";
import { tr } from "../i18n/runtime";
import { Component, type ErrorInfo, type PropsWithChildren } from "react";

export class ErrorBoundary extends Component<
    PropsWithChildren,
    { failed: boolean }
> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    componentDidCatch(_error: Error, _info: ErrorInfo) {}
    render() {
        return this.state.failed ? <ErrorFallback /> : this.props.children;
    }
}
function ErrorFallback() {
    useLocale();
    return (
        <div className="fatal-error" role="alert">
            <h1>{tr("mca119e0b0d")}</h1>
            <p>{tr("m6f946d1b44")}</p>
            <button className="button" onClick={() => window.location.reload()}>
                {tr("mb98662b701")}
            </button>
        </div>
    );
}
