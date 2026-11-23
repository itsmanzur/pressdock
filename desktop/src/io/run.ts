import { parseFile, type ParsedSheet } from './parse';
import { buildPlan, type ImportConfig, type PlanContext, type PlanSummary, type PlannedRow } from './plan';

export type WorkerRequest =
  | { id: number; type: 'parse'; buf: ArrayBuffer; name: string; sheet?: string }
  | { id: number; type: 'plan'; parsed: ParsedSheet; cfg: ImportConfig; ctx: PlanContext };

export type WorkerResponse =
  | { id: number; type: 'progress'; p: number }
  | { id: number; type: 'done'; result: unknown }
  | { id: number; type: 'error'; message: string };

export type PlanResult = { rows: PlannedRow[]; summary: PlanSummary };

let worker: Worker | null = null;
let seq = 0;

function getWorker(): Worker | null {
  if (typeof Worker === 'undefined') return null;
  if (!worker) {
    try {
      worker = new Worker(new URL('./worker.ts', import.meta.url), { type: 'module' });
    } catch {
      return null;
    }
  }
  return worker;
}

type Req = WorkerRequest extends infer R ? (R extends { id: number } ? Omit<R, 'id'> : never) : never;

function call<T>(req: Req, onProgress?: (p: number) => void, transfer: Transferable[] = []): Promise<T> | null {
  const w = getWorker();
  if (!w) return null;
  const id = ++seq;
  return new Promise<T>((resolve, reject) => {
    const onMsg = (ev: MessageEvent<WorkerResponse>) => {
      const m = ev.data;
      if (m.id !== id) return;
      if (m.type === 'progress') onProgress?.(m.p);
      else {
        w.removeEventListener('message', onMsg);
        w.removeEventListener('error', onErr);
        if (m.type === 'done') resolve(m.result as T);
        else reject(new Error(m.message));
      }
    };
    const onErr = (e: ErrorEvent) => {
      w.removeEventListener('message', onMsg);
      w.removeEventListener('error', onErr);
      worker = null; // next call spawns a fresh one
      reject(new Error(e.message || 'Worker failed'));
    };
    w.addEventListener('message', onMsg);
    w.addEventListener('error', onErr);
    w.postMessage({ ...req, id } as WorkerRequest, transfer);
  });
}

/** Parse in a Web Worker (UI stays responsive); falls back to the main thread where workers are unavailable. */
export async function parseInWorker(buf: ArrayBuffer, name: string, sheet?: string, onProgress?: (p: number) => void): Promise<ParsedSheet> {
  const copy = buf.slice(0); // keep the original for sheet switching
  const p = call<ParsedSheet>({ type: 'parse', buf: copy, name, sheet }, onProgress, [copy]);
  if (p) {
    try {
      return await p;
    } catch {
      /* fall through to main thread */
    }
  }
  return parseFile(buf, name, { sheet }, onProgress);
}

export async function planInWorker(parsed: ParsedSheet, cfg: ImportConfig, ctx: PlanContext, onProgress?: (p: number) => void): Promise<PlanResult> {
  const p = call<PlanResult>({ type: 'plan', parsed, cfg, ctx }, onProgress);
  if (p) {
    try {
      return await p;
    } catch {
      /* fall through */
    }
  }
  return buildPlan(parsed, cfg, ctx, (d, t) => onProgress?.(t ? d / t : 1));
}
