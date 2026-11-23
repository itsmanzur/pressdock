/// <reference lib="webworker" />
import { parseFile } from './parse';
import { buildPlan } from './plan';
import type { WorkerRequest, WorkerResponse } from './run';

const post = (m: WorkerResponse) => (self as unknown as Worker).postMessage(m);

self.onmessage = async (ev: MessageEvent<WorkerRequest>) => {
  const req = ev.data;
  try {
    if (req.type === 'parse') {
      const result = parseFile(req.buf, req.name, { sheet: req.sheet }, (p) => post({ id: req.id, type: 'progress', p }));
      post({ id: req.id, type: 'done', result });
    } else {
      const result = await buildPlan(req.parsed, req.cfg, req.ctx, (d, t) => post({ id: req.id, type: 'progress', p: t ? d / t : 1 }));
      post({ id: req.id, type: 'done', result });
    }
  } catch (e) {
    post({ id: req.id, type: 'error', message: e instanceof Error ? e.message : String(e) });
  }
};
