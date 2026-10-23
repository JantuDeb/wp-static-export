export interface Size {
  width: number;
  height: number;
}

/** Read intrinsic dimensions from image bytes without decoding. Returns null if unknown. */
export function imageSize(buf: Buffer): Size | null {
  if (buf.length < 12) return null;

  // PNG
  if (buf.readUInt32BE(0) === 0x89504e47) return buf.length >= 24 ? { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) } : null;

  // GIF
  if (buf.toString('ascii', 0, 4) === 'GIF8') return { width: buf.readUInt16LE(6), height: buf.readUInt16LE(8) };

  // JPEG: walk segments until a start-of-frame marker.
  if (buf[0] === 0xff && buf[1] === 0xd8) {
    let off = 2;
    while (off + 9 < buf.length) {
      if (buf[off] !== 0xff) {
        off++;
        continue;
      }
      const marker = buf[off + 1] as number;
      if (marker === 0xff) {
        off++;
        continue;
      }
      if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
        return orient(buf, { height: buf.readUInt16BE(off + 5), width: buf.readUInt16BE(off + 7) });
      }
      off += 2 + buf.readUInt16BE(off + 2);
    }
    return null;
  }

  // WebP
  if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') {
    const kind = buf.toString('ascii', 12, 16);
    if (kind === 'VP8 ' && buf.length >= 30) return { width: buf.readUInt16LE(26) & 0x3fff, height: buf.readUInt16LE(28) & 0x3fff };
    if (kind === 'VP8L' && buf.length >= 25) {
      const b = buf.readUInt32LE(21);
      return { width: (b & 0x3fff) + 1, height: ((b >> 14) & 0x3fff) + 1 };
    }
    if (kind === 'VP8X' && buf.length >= 30) return { width: 1 + buf.readUIntLE(24, 3), height: 1 + buf.readUIntLE(27, 3) };
    return null;
  }

  // SVG
  const head = buf.toString('utf8', 0, Math.min(buf.length, 2048));
  if (/<svg\b/i.test(head)) return svgSize(head);
  return null;
}

/** EXIF orientation 5-8 means the stored width/height are swapped relative to display. */
function orient(buf: Buffer, size: Size): Size {
  const exif = buf.indexOf('Exif\0\0');
  if (exif < 0 || exif > 4096) return size;
  const tiff = exif + 6;
  const little = buf.toString('ascii', tiff, tiff + 2) === 'II';
  const u16 = (o: number) => (little ? buf.readUInt16LE(o) : buf.readUInt16BE(o));
  const u32 = (o: number) => (little ? buf.readUInt32LE(o) : buf.readUInt32BE(o));
  try {
    const ifd = tiff + u32(tiff + 4);
    const n = u16(ifd);
    for (let i = 0; i < n; i++) {
      const e = ifd + 2 + i * 12;
      if (u16(e) === 0x0112) {
        const o = u16(e + 8);
        return o >= 5 && o <= 8 ? { width: size.height, height: size.width } : size;
      }
    }
  } catch {
    /* truncated EXIF: use stored size */
  }
  return size;
}

function svgSize(head: string): Size | null {
  const tag = /<svg\b[^>]*>/i.exec(head)?.[0] ?? '';
  const num = (name: string) => {
    const m = new RegExp(`\\s${name}\\s*=\\s*["']\\s*([0-9.]+)\\s*(px)?\\s*["']`, 'i').exec(tag);
    return m ? Number(m[1]) : null;
  };
  const w = num('width');
  const h = num('height');
  if (w && h) return { width: Math.round(w), height: Math.round(h) };
  const vb = /viewBox\s*=\s*["']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["']/i.exec(tag);
  return vb ? { width: Math.round(Number(vb[1])), height: Math.round(Number(vb[2])) } : null;
}
