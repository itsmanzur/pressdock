/**
 * Sync engine boundary (NEXT STEP — not implemented yet).
 *
 * The UI never talks to the network for record data. It only writes to SQLite (records + outbox)
 * through `SiteRepo`. The sync engine will live entirely in `src/sync/` and use:
 *   - `SiteRepo.listOutbox()`        → ops to POST /push (batches ≤ limits.max_ops)
 *   - `PressdockApi`                 → add push()/pull()/undo() beside handshake()/entities()
 *   - tables `records`, `sync_log`   → apply results, conflicts, cursors
 *
 * The UI only depends on this interface, so the engine can be dropped in without touching components.
 */
export interface SyncSummary {
  pushed: number;
  pulled: number;
  conflicts: number;
  errors: number;
  batchId: string | null;
}

export interface SyncEngine {
  /** Run one push+pull cycle for a site. */
  syncNow(siteId: string): Promise<SyncSummary>;
  /** Undo the last pushed batch on the server (POST /undo). */
  undoBatch(siteId: string, batchId: string): Promise<void>;
}

/** Placeholder until the engine exists; the UI shows the Sync button disabled when this is null. */
export const syncEngine: SyncEngine | null = null;
