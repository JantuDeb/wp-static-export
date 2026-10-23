export interface FileEntry {
  /** POSIX path relative to the export root, no leading slash. */
  path: string;
  size: number;
  /** Hex SHA-256 of the file contents. */
  sha256: string;
  abs: string;
}

export interface Logger {
  info(message: string): void;
  warn(message: string): void;
  progress(done: number, total: number, label?: string): void;
}

export interface DeployContext<C = Record<string, unknown>> {
  dir: string;
  /** Every file in the export. */
  all: FileEntry[];
  /** Files that are new or changed since the last successful deploy (all files on a full deploy). */
  changed: FileEntry[];
  /** Paths that existed in the previous deploy and are gone now. Empty on partial exports. */
  deleted: string[];
  config: C;
  concurrency: number;
  log: Logger;
}

export interface DeployResult {
  url?: string;
  uploaded: number;
  deleted: number;
  message?: string;
}

export type ProviderId = 'github' | 'netlify' | 'cloudflare-pages' | 's3' | 'bunny' | 'sftp';

export interface Provider<C = any> {
  id: ProviderId;
  /** Throws with a readable message if config is incomplete. */
  validate(config: unknown): C;
  deploy(ctx: DeployContext<C>): Promise<DeployResult>;
}

export interface Job {
  provider: ProviderId;
  /** Absolute path of the static export. */
  dir: string;
  /** JSON file remembering hashes from the last successful deploy. Enables incremental deploys. */
  stateFile?: string;
  /**
   * full: export contains the whole site, so missing files are deleted remotely.
   * partial: export contains only some pages (single/update exports); nothing is deleted.
   */
  scope?: 'full' | 'partial';
  /** Ignore saved state and upload everything. */
  force?: boolean;
  concurrency?: number;
  /** Skip remote deletions if they would remove more than this share of previously deployed files. Default 0.5. */
  maxDeleteRatio?: number;
  config: Record<string, unknown>;
}

export class DeployError extends Error {}
