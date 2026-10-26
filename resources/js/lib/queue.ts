export function makeOrder(
    length: number,
    current: number,
    shuffled: boolean,
    random = Math.random,
): number[] {
    const all = Array.from({ length }, (_, i) => i);
    if (!shuffled || !length) return all;
    const rest = all.filter((i) => i !== current);
    for (let i = rest.length - 1; i > 0; i--) {
        const j = Math.floor(
            Math.min(0.999999, Math.max(0, random())) * (i + 1),
        );
        [rest[i], rest[j]] = [rest[j], rest[i]];
    }
    return [current, ...rest];
}
export function adjacent(
    order: number[],
    current: number,
    direction: 1 | -1,
    wrap: boolean,
): number | null {
    if (!order.length) return null;
    const next = order.indexOf(current) + direction;
    if (next < 0 || next >= order.length)
        return wrap ? order[(next + order.length) % order.length] : null;
    return order[next];
}
