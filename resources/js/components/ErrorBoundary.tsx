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
        if (this.state.failed)
            return (
                <div className="fatal-error">
                    <h1>نمایش صفحه متوقف شد</h1>
                    <p>صفحه را تازه کن و دوباره امتحان کن.</p>
                    <button
                        className="button"
                        onClick={() => window.location.reload()}
                    >
                        تازه‌سازی
                    </button>
                </div>
            );
        return this.props.children;
    }
}
