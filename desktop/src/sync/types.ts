import type { RecordData, ServerSnapshot } from '../schema/types';

/** Wire format of POST /push ops (docs/api-contract.md §5). */
export interface PushOp {
  op_id: string;
  op: 'create' | 'update' | 'delete';
  entity: string;
  uuid?: string;
  remote_id?: number;
  base_revision?: number;
  data?: RecordData;
  force?: boolean;
}

export interface PushResult {
  op_id: string;
  status: 'applied' | 'conflict' | 'error';
  remote_id?: number;
  revision?: number;
  reason?: string;
  field?: string;
  server?: ServerSnapshot;
  error?: { code: string; message: string; field?: string };
  replayed?: boolean;
}

export interface PushResponse {
  batch_id: string;
  results: PushResult[];
  cursor?: number;
}

export interface PullItem {
  entity: string;
  remote_id: number;
  uuid: string;
  revision: number;
  deleted: boolean;
  data: RecordData | null;
  action?: string;
  cursor?: number;
}

export interface PullResponse {
  items: PullItem[];
  next_cursor: number;
  has_more: boolean;
  baseline_pending?: boolean;
}

export interface HandshakeInfo {
  site?: { api_version?: string };
  limits: { max_ops: number; pull_default: number; pull_max: number };
  cursor: number;
}

/** The slice of the Connector API the engine needs. PressdockApi implements it; tests use a mock server. */
export interface SyncApi {
  handshake(): Promise<HandshakeInfo>;
  push(batchId: string, ops: PushOp[]): Promise<PushResponse>;
  pull(entity: string, since: number, limit: number): Promise<PullResponse>;
  undo(batchId: string): Promise<{ restored: number; errors: unknown[] }>;
}

export interface SyncSummary {
  pushed: number;
  pulled: number;
  conflicts: number;
  errors: number;
  batchIds: string[];
  /** set when the run stopped early */
  aborted?: 'busy' | 'cancelled' | 'offline' | 'failed';
  message?: string;
}

export interface PlanGroup {
  entity: string;
  creates: number;
  updates: number;
  deletes: number;
  /** a few record labels for the preview */
  samples: { op: 'create' | 'update' | 'delete'; uuid: string }[];
}

export interface SyncPlan {
  total: number;
  creates: number;
  updates: number;
  deletes: number;
  groups: PlanGroup[];
  /** true when the spec's safety rule asks for confirmation: any delete, or more than 20 changes */
  needsConfirm: boolean;
}

export interface SyncSettings {
  autoSync: boolean;
  intervalMinutes: number;
}

export const CONFIRM_THRESHOLD = 20;
export const DEFAULT_BATCH = 50;
