import { useEffect, useRef } from "react";
import { createDitherFlow, type EffectFailure, type Palette } from "./renderer";

export default function DitherFlowCanvas({
    palette,
    onFailure,
}: {
    palette: Palette;
    onFailure: (reason: EffectFailure) => void;
}) {
    const ref = useRef<HTMLCanvasElement>(null);
    useEffect(() => {
        if (ref.current)
            return createDitherFlow(ref.current, palette, onFailure);
    }, [palette, onFailure]);
    return <canvas ref={ref} className="shader-canvas" aria-hidden="true" />;
}
