/**
 * Sync engine public surface. The UI talks to the network for record data ONLY through this module
 * (engine.ts = push/pull, resolve.ts = conflicts + undo, collapse.ts = op merging/ordering).
 */
export { SyncEngine, isSyncing } from './engine';
export { resolveConflict, undoSyncBatch, diffFields } from './resolve';
export { collapseOps, orderByDependency } from './collapse';
export * from './types';
