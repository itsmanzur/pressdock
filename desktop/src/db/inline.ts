import type { SqlValue, Stmt } from '../platform/types';

/** Render one value as a SQLite literal. Strings: quotes doubled, NUL stripped (never valid in text). */
export function sqlLiteral(v: SqlValue): string {
  if (v === null || v === undefined) return 'NULL';
  if (typeof v === 'number') return Number.isFinite(v) ? String(v) : 'NULL';
  return `'${String(v).split('\u0000').join('').split("'").join("''")}'`;
}

/** Replace `$n` placeholders with literals. */
export function inlineParams(sql: string, params: SqlValue[] = []): string {
  return sql.replace(/\$(\d+)/g, (_m, n: string) => {
    const idx = Number(n) - 1;
    if (idx < 0 || idx >= params.length) throw new Error(`Missing parameter $${n}`);
    return sqlLiteral(params[idx]);
  });
}

/**
 * Build one multi-statement script wrapped in a transaction.
 * tauri-plugin-sql uses a connection pool, so separate BEGIN/COMMIT calls could land on
 * different connections; a single `execute` of one script always stays on one connection.
 */
export function transactionScript(stmts: Stmt[]): string {
  const body = stmts.map((s) => inlineParams(s.sql, s.params).trim().replace(/;+\s*$/, '')).join(';\n');
  return `BEGIN IMMEDIATE;\n${body};\nCOMMIT;`;
}
