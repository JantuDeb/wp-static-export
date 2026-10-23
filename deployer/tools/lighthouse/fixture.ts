import { copyFile, mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

/** A photo-like image: smooth colour fields with grain, so JPEG/WebP behave like real photos. */
export async function photo(width: number, height: number, seed: number, quality: number): Promise<Buffer> {
  const raw = Buffer.alloc(width * height * 3);
  let s = seed;
  const rnd = () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296) * 18;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 3;
      const wave = Math.sin(x / 90 + seed) * 40 + Math.cos(y / 70) * 40;
      raw[i] = 110 + wave + (x / width) * 80 + rnd();
      raw[i + 1] = 120 + wave * 0.7 + (y / height) * 70 + rnd();
      raw[i + 2] = 140 - wave * 0.5 + rnd();
    }
  }
  return sharp(raw, { raw: { width, height, channels: 3 } })
    .jpeg({ quality, mozjpeg: false })
    .toBuffer();
}

// Typical un-minified theme CSS/JS: comments, generous whitespace, many similar rules.
function themeCss(): string {
  const rules: string[] = ['/*! Theme: Example, generated for the Lighthouse demo */'];
  for (let i = 0; i < 160; i++) {
    rules.push(
      `/* component ${i} */\n.component-${i} {\n    margin: ${i % 7}px ${i % 5}px;\n    padding: ${(i % 4) + 1}rem;\n    color: #${((i * 2654435) & 0xffffff).toString(16).padStart(6, '0')};\n    border: 1px solid rgba(0, 0, 0, 0.${i % 9});\n}`,
    );
  }
  return (
    `@font-face {\n    font-family: 'Demo Sans';\n    src: url('/fonts/demo.ttf') format('truetype');\n    font-weight: 400;\n}\n\n:root { --max: 1100px; }\n*, *::before, *::after { box-sizing: border-box; }\nbody { margin: 0; font-family: 'Demo Sans', system-ui, sans-serif; line-height: 1.6; color: #1a1a1a; background: #fff; }\nimg { max-width: 100%; }\n.wrap { max-width: var(--max); margin: 0 auto; padding: 0 1rem; }\nheader.site { padding: 1rem 0; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }\nnav a { margin-left: 1rem; color: #0b57d0; }\n.hero img { width: 100%; height: auto; }\n.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }\n.card img { width: 100%; height: auto; display: block; }\nfooter.site { margin-top: 3rem; padding: 2rem 0; background: #f4f4f4; }\n` +
    rules.join('\n\n')
  );
}

function themeJs(): string {
  const fns: string[] = ['/* Theme behaviour (readable, un-minified) */', '(function () {', '    "use strict";'];
  for (let i = 0; i < 90; i++) {
    fns.push(
      `    // handler ${i}\n    function handler${i}(element, options) {\n        var settings = options || {};\n        if (!element) {\n            return null;\n        }\n        element.setAttribute("data-handler-${i}", String(settings.value || ${i}));\n        return element.getAttribute("data-handler-${i}");\n    }`,
    );
  }
  fns.push(
    '    document.querySelectorAll("[data-demo]").forEach(function (el, index) {',
    '        handler' + 0 + '(el, { value: index });',
    '    });',
    '})();',
  );
  return fns.join('\n');
}

const cards = (n: number, prefix: string) =>
  Array.from(
    { length: n },
    (_, i) =>
      `<article class="card"><a href="/blog/hello-world/"><img src="/wp-content/uploads/${prefix}-${i + 1}.jpg" ${i === 1 ? 'alt="Mountain lake at dawn"' : ''}></a><h3><a href="/blog/hello-world/">Post title ${i + 1}</a></h3><p>An excerpt that describes post number ${i + 1} in a sentence or two, the way a theme prints it.</p></article>`,
  ).join('\n');

function page(opts: { title: string; path: string; viewport?: boolean; lang?: boolean; main: string; description?: string }): string {
  return `<!DOCTYPE html>
<html${opts.lang === false ? '' : ' lang="en-US"'}>
<head>
<meta charset="UTF-8">
${opts.viewport === false ? '' : '<meta name="viewport" content="width=device-width, initial-scale=1">\n'}<title>${opts.title}</title>
${opts.description ? `<meta name="description" content="${opts.description}">\n` : ''}<link rel="stylesheet" href="/style.css">
</head>
<body>
<header class="site wrap"><a href="/"><img src="/wp-content/uploads/logo.svg" width="48" height="48" alt="Site logo"></a>
<nav><a href="/">Home</a><a href="/about/">About</a><a href="/blog/hello-world/">Blog</a></nav></header>
<main class="wrap">
${opts.main}
</main>
<footer class="site"><div class="wrap"><p>Built with WordPress. Follow us: <a href="https://example.org/social" target="_blank">Social</a> · <a href="https://example.org/press" target="_blank" rel="nofollow">Press</a></p></div></footer>
<script src="/script.js"></script>
</body>
</html>
`;
}

/** Write a WordPress-looking static export with the usual Lighthouse problems into `dir`. */
export async function makeFixture(dir: string): Promise<string> {
  const w = async (rel: string, data: string | Buffer) => {
    const abs = path.join(dir, rel);
    await mkdir(path.dirname(abs), { recursive: true });
    await writeFile(abs, data);
  };

  await w('wp-content/uploads/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#0b57d0"/></svg>');
  await w('wp-content/uploads/hero.jpg', await photo(1600, 900, 3, 92));
  for (let i = 1; i <= 3; i++) await w(`wp-content/uploads/post-${i}.jpg`, await photo(800, 450, 10 + i, 90));
  await w('wp-content/uploads/team-1.jpg', await photo(800, 450, 31, 90));
  await w('style.css', themeCss());
  await w('script.js', themeJs());
  const font = '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf';
  if (existsSync(font)) {
    await mkdir(path.join(dir, 'fonts'), { recursive: true });
    await copyFile(font, path.join(dir, 'fonts/demo.ttf'));
  }

  await w(
    'index.html',
    page({
      title: 'Demo Site – Just another WordPress site',
      path: '/',
      main: `<section class="hero"><img src="/wp-content/uploads/hero.jpg"></section>
<h1>Welcome to the demo site</h1>
<p>This site was exported from WordPress to plain HTML so it can be hosted anywhere, quickly and cheaply, with no PHP or database.</p>
<section class="cards">
${cards(3, 'post')}
</section>`,
    }),
  );
  await w(
    'about/index.html',
    page({
      title: 'About – Demo Site',
      path: '/about/',
      description: 'Learn about the team behind the demo site.',
      main: `<h1>About us</h1><p>We are a small team that builds fast websites and writes about how we do it, from hosting to performance.</p><img src="/wp-content/uploads/team-1.jpg" alt="Our team"><p>Reach us any time through the contact page.</p>`,
    }),
  );
  await w(
    'blog/hello-world/index.html',
    page({
      title: 'Hello world – Demo Site',
      path: '/blog/hello-world/',
      viewport: false,
      main: `<article><h1>Hello world</h1><p>Welcome to WordPress. This is your first post. Edit or delete it, then start writing and publishing something people want to read.</p><img src="/wp-content/uploads/post-1.jpg"><p>More text after the picture so the page has some length to it and the image is not the only content.</p><img src="/wp-content/uploads/post-2.jpg"></article>`,
    }),
  );
  await w(
    '404.html',
    page({
      title: 'Page not found',
      path: '/404.html',
      main: '<h1>Page not found</h1><p>Sorry, nothing lives at this address. Try the home page instead.</p>',
    }),
  );
  return dir;
}
