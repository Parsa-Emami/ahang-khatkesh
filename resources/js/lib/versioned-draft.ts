export type VersionedDraft<T> = {
    items: T[];
    revision: number;
    dirty: boolean;
    remoteUpdate: boolean;
};
export function freshDraft<T>(items: T[], revision: number): VersionedDraft<T> {
    return { items, revision, dirty: false, remoteUpdate: false };
}
export function receiveDraft<T>(
    draft: VersionedDraft<T>,
    items: T[],
    revision: number,
): VersionedDraft<T> {
    return draft.dirty
        ? {
              ...draft,
              remoteUpdate: draft.remoteUpdate || revision !== draft.revision,
          }
        : freshDraft(items, revision);
}
export function editDraft<T>(
    draft: VersionedDraft<T>,
    items: T[],
): VersionedDraft<T> {
    return { ...draft, items, dirty: true };
}
