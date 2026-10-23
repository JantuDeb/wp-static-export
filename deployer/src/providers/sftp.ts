import path from 'node:path/posix';
import SftpClient from 'ssh2-sftp-client';
import { readFile } from 'node:fs/promises';
import { required } from '../util.js';
import { DeployError, type Provider } from '../types.js';

export interface SftpConfig {
  host: string;
  port?: number;
  username: string;
  password?: string;
  /** PEM/OpenSSH private key contents. */
  privateKey?: string;
  passphrase?: string;
  /** Remote base folder. */
  folder: string;
}

export const sftp: Provider<SftpConfig> = {
  id: 'sftp',
  validate(c) {
    const cfg = c as unknown as Record<string, unknown>;
    required(cfg, ['host', 'username', 'folder'], 'sftp');
    if (!cfg.password && !cfg.privateKey) throw new DeployError('sftp: provide a password or privateKey');
    return c as unknown as SftpConfig;
  },
  async verify(config) {
    const client = new SftpClient();
    try {
      await client.connect({
        host: config.host,
        port: config.port ?? 22,
        username: config.username,
        password: config.password,
        privateKey: config.privateKey,
        passphrase: config.passphrase,
        readyTimeout: 15000,
      });
      const kind = await client.exists(config.folder);
      if (!kind) throw new DeployError(`Connected, but ${config.folder} does not exist on the server`);
      return { message: `Connected to ${config.host}, ${config.folder} exists` };
    } finally {
      await client.end().catch(() => undefined);
    }
  },
  async deploy({ changed, deleted, config, log }) {
    const client = new SftpClient();
    await client.connect({
      host: config.host,
      port: config.port ?? 22,
      username: config.username,
      password: config.password,
      privateKey: config.privateKey,
      passphrase: config.passphrase,
    });
    try {
      const root = config.folder.replace(/\/+$/, '') || '/';
      const made = new Set<string>();
      let done = 0;
      for (const f of changed) {
        const remote = path.join(root, f.path);
        const dir = path.dirname(remote);
        if (!made.has(dir)) {
          await client.mkdir(dir, true);
          made.add(dir);
        }
        // Upload to a temp name then rename so visitors never see a half-written file.
        const tmp = `${remote}.part`;
        await client.put(await readFile(f.abs), tmp);
        await client.rename(tmp, remote).catch(async () => {
          await client.delete(remote).catch(() => undefined);
          await client.rename(tmp, remote);
        });
        log.progress(++done, changed.length, 'files');
      }
      for (const p of deleted) await client.delete(path.join(root, p)).catch(() => undefined);
      return { uploaded: changed.length, deleted: deleted.length, message: `Uploaded to ${config.host}:${root}` };
    } finally {
      await client.end();
    }
  },
};
