export type ContrastPair = { theme: string; foreground: string; background: string; minimum: number; ratio: number };
export function contrast(a: string, b: string): number;
export function contrastMatrix(): ContrastPair[];
