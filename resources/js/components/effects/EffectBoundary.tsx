import { Component, type PropsWithChildren } from "react";

export class EffectBoundary extends Component<
    PropsWithChildren<{
        onFailure: () => void;
    }>,
    { failed: boolean }
> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    componentDidCatch() {
        this.props.onFailure();
    }
    render() {
        return this.state.failed ? null : this.props.children;
    }
}
