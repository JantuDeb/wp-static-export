import { bunny } from './providers/bunny.js';
import { cloudflarePages } from './providers/cloudflare-pages.js';
import { cloudflareWorkers } from './providers/cloudflare-workers.js';
import { github } from './providers/github.js';
import { netlify } from './providers/netlify.js';
import { s3Provider } from './providers/s3.js';
import { sftp } from './providers/sftp.js';
import type { Provider, ProviderId } from './types.js';

export const providers: Record<ProviderId, Provider> = {
  github,
  netlify,
  'cloudflare-pages': cloudflarePages,
  'cloudflare-workers': cloudflareWorkers,
  s3: s3Provider,
  bunny,
  sftp,
};
