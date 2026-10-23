export type FieldType = 'text' | 'password' | 'textarea' | 'number' | 'checkbox' | 'select' | 'repeater';

export interface Field {
  key: string;
  label: string;
  type: FieldType;
  secret?: boolean;
  help?: string;
  default?: unknown;
  options?: Record<string, string>;
  show_if?: Record<string, unknown>;
  columns?: Field[];
}

export interface Section {
  id: string;
  label: string;
  description?: string;
  fields: Field[];
}

export type Values = Record<string, unknown>;

/** Marker the server interprets as "delete the stored secret". Must match Features_Admin::CLEAR_MARK. */
export const CLEAR_MARK = '\u0000clear';

/** A field is visible when every show_if condition matches the current values. */
export function isVisible(field: Field, values: Values): boolean {
  if (!field.show_if) return true;
  return Object.entries(field.show_if).every(([key, expected]) => {
    const actual = values[key];
    if (Array.isArray(expected)) return expected.map(String).includes(String(actual));
    if (typeof expected === 'boolean') return Boolean(actual) === expected;
    return String(actual ?? '') === String(expected);
  });
}

export interface PayloadInput {
  schema: Section[];
  values: Values;
  /** Secret fields the user typed into (key -> new value). */
  secretEdits: Record<string, string>;
  /** Secret fields the user asked to clear. */
  secretClears: Set<string>;
  locked: Record<string, boolean>;
}

/** Build the POST body: skip locked fields, send secrets only if edited or cleared. */
export function buildPayload({ schema, values, secretEdits, secretClears, locked }: PayloadInput): Values {
  const out: Values = {};
  for (const section of schema) {
    for (const field of section.fields) {
      if (locked[field.key]) continue;
      if (field.secret) {
        if (secretClears.has(field.key)) out[field.key] = CLEAR_MARK;
        else if (secretEdits[field.key]) out[field.key] = secretEdits[field.key];
        continue;
      }
      out[field.key] = values[field.key];
    }
  }
  return out;
}

export function defaultRow(columns: Field[]): Values {
  return Object.fromEntries(columns.map((c) => [c.key, c.default ?? (c.type === 'checkbox' ? false : '')]));
}
