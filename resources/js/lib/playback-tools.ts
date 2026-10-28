import type { Track } from "../types";
import { makeOrder } from "./queue";
export type EditableQueue<T> = { queue: T[]; index: number; playing: boolean };
export function insertNext<T extends { id: number }>(
    state: EditableQueue<T>,
    track: T,
): EditableQueue<T> {
    const current = state.queue[state.index];
    if (
        current?.id === track.id ||
        (state.queue.length >= 500 &&
            !state.queue.some((t) => t.id === track.id))
    )
        return state;
    const queue = state.queue.filter((t) => t.id !== track.id);
    const index = current ? queue.findIndex((t) => t.id === current.id) : 0;
    queue.splice(current ? index + 1 : 0, 0, track);
    return { ...state, queue, index };
}
export function removeQueued<T>(
    state: EditableQueue<T>,
    at: number,
): EditableQueue<T> {
    if (at < 0 || at >= state.queue.length) return state;
    const queue = state.queue.filter((_, i) => i !== at);
    const index = Math.max(
        0,
        Math.min(
            queue.length - 1,
            at < state.index ? state.index - 1 : state.index,
        ),
    );
    return {
        ...state,
        queue,
        index,
        playing:
            state.playing &&
            queue.length > 0 &&
            !(at === state.index && at >= queue.length),
    };
}
export function moveQueued<T extends { id: number }>(
    state: EditableQueue<T>,
    at: number,
    offset: -1 | 1,
): EditableQueue<T> {
    const target = at + offset;
    if (
        at < 0 ||
        at >= state.queue.length ||
        target < 0 ||
        target >= state.queue.length
    )
        return state;
    const current = state.queue[state.index];
    const queue = [...state.queue];
    [queue[at], queue[target]] = [queue[target], queue[at]];
    return {
        ...state,
        queue,
        index: queue.findIndex((t) => t.id === current.id),
    };
}
export function trimUpcoming<T>(state: EditableQueue<T>): EditableQueue<T> {
    return { ...state, queue: state.queue.slice(0, state.index + 1) };
}
export function balancedOrder(
    queue: Track[],
    current: number,
    random = Math.random,
): number[] {
    if (!queue.length) return [];
    const candidates = makeOrder(queue.length, current, true, random).slice(1);
    const artist = (index: number) => {
        const primary = queue[index].artists
            ?.filter((a) => a.role === "primary")
            .map((a) => a.id)
            .sort((a, b) => a - b);
        return primary?.length
            ? primary.join(",")
            : queue[index].artist.trim().toLocaleLowerCase();
    };
    const order = [current];
    while (candidates.length) {
        const alternative = candidates.findIndex(
            (i) => artist(i) !== artist(order[order.length - 1]),
        );
        order.push(candidates.splice(alternative < 0 ? 0 : alternative, 1)[0]);
    }
    return order;
}
