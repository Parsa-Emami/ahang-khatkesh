# ahang-khatkesh development contract

Read README.md and docs/ARCHITECTURE.fa.md before changing the application. PHP is 8.5.x, Laravel is 13, and the frontend is React 19 with TypeScript. Check lockfiles for exact installed versions. Follow the retained Laravel Boost skills in .claude/skills for Laravel and test work.

All business communication uses same-origin Laravel /api/v1 endpoints. Keep session authentication and CSRF enabled. Never expose private media paths, upload audio under public, commit .env, or commit real user/media data. The ten-account cap includes administrators and inactive accounts. Preserve its serialized database invariant. Playlist visibility applies to metadata, covers and streams.

Keep controllers focused on HTTP. Use existing Actions, Resources, Policies and parser strategies. Database queue writes must share the application database transaction. Jobs must be idempotent. Preserve optimistic revisions for playback. Use new migrations after a schema has been shared.

Keep the player above route components. Progress must come from Audio events. Preserve RTL, light/dark/system modes, keyboard access, accessible button names and reduced-motion support. Keep fonts local and use the existing UI primitives. Do not add code comments; use clear names and explain design decisions in docs.

Run meaningful PHP feature tests, queue tests, typecheck/build, and dependency audits. Run Pint on changed PHP. Report browser, MySQL, load or deployment checks as unverified when they have not run. Do not invent measurements. New dependencies must solve a concrete requirement.

Do not send messages, publish deployments, or modify unrelated repositories as part of routine development. The requested remote is the independent ahang-khatkesh repository selected by the owner.
