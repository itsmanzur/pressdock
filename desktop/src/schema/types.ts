export type FieldType =
  | 'string'
  | 'int'
  | 'float'
  | 'bool'
  | 'date'
  | 'datetime'
  | 'enum'
  | 'text'
  | 'html'
  | 'relation'
  | 'media'
  | 'list'
  | 'object';

export interface FieldDef {
  type: FieldType;
  label?: string;
  required?: boolean;
  readonly?: boolean;
  create_only?: boolean;
  /** enum: allowed values (array of strings, or an object whose keys are the values). */
  options?: string[] | Record<string, string>;
  /** relation: target entity key. */
  target?: string;
  max_length?: number;
  max_items?: number;
  item_max_length?: number;
  /** object: nested sub-schema. */
  properties?: Record<string, FieldDef>;
}

export type EntitySchema = Record<string, FieldDef>;

export interface EntityActions {
  read: boolean;
  create: boolean;
  update: boolean;
  delete: boolean;
}

export interface Entity {
  key: string;
  label: string;
  group: string;
  actions: EntityActions;
  schema: EntitySchema;
  cursor: number | null;
}

export type RecordStatus = 'synced' | 'dirty' | 'new' | 'deleted' | 'conflict';
export type RecordData = Record<string, unknown>;

/** Server state kept next to a conflicted record. `_reason`/`_field` are set for unique-value conflicts. */
export interface ServerSnapshot {
  entity?: string;
  remote_id: number | null;
  uuid?: string;
  revision: number;
  deleted: boolean;
  data: RecordData | null;
  _reason?: string;
  _field?: string;
}

export interface LocalRecord {
  uuid: string;
  entity: string;
  remote_id: number | null;
  data: RecordData;
  base_revision: number;
  status: RecordStatus;
  updated_at: string;
  /** user asked to delete this record when the conflict arose */
  local_deleted?: boolean;
  server?: ServerSnapshot | null;
}

export function enumOptions(def: FieldDef): { value: string; label: string }[] {
  const o = def.options;
  if (!o) return [];
  if (Array.isArray(o)) return o.map((v) => ({ value: String(v), label: String(v) }));
  return Object.entries(o).map(([value, label]) => ({ value, label: String(label) }));
}

/** Simple scalar types that can be edited right inside a grid cell. */
export const INLINE_TYPES: FieldType[] = ['string', 'int', 'float', 'bool', 'enum', 'date'];

export const fieldLabel = (key: string, def: FieldDef): string => def.label || key;
