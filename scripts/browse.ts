import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const EDGE = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const PORT = 9473;

interface Options {
  url: string;
  evals: string[];
  wait: number;
  out: string | null;
  width: number;
  height: number;
}

function parse(argv: string[]): Options {
  const o: Options = { url: 'http://localhost:5181/', evals: [], wait: 1500, out: null, width: 1400, height: 900 };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--eval') o.evals.push(argv[++i]);
    else if (a === '--wait') o.wait = Number(argv[++i]);
    else if (a === '--out') o.out = argv[++i];
    else if (a === '--size') {
      const [w, h] = argv[++i].split('x').map(Number);
      o.width = w;
      o.height = h;
    } else o.url = a;
  }
  return o;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

class Cdp {
  private ws: WebSocket;
  private seq = 0;
  private pending = new Map<number, { resolve: (v: unknown) => void; reject: (e: Error) => void }>();
  readonly events: { method: string; params: Record<string, unknown> }[] = [];

  constructor(ws: WebSocket) {
    this.ws = ws;
    ws.addEventListener('message', (m) => {
      const msg = JSON.parse(String(m.data));
      if (msg.id !== undefined) {
        const p = this.pending.get(msg.id);
        if (!p) return;
        this.pending.delete(msg.id);
        if (msg.error) p.reject(new Error(msg.error.message));
        else p.resolve(msg.result);
      } else {
        this.events.push({ method: msg.method, params: msg.params });
      }
    });
  }

  send(method: string, params: Record<string, unknown> = {}): Promise<unknown> {
    const id = ++this.seq;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
}

async function main() {
  const opt = parse(process.argv.slice(2));
  const busy = await fetch(`http://127.0.0.1:${PORT}/json/version`).then(() => true, () => false);
  if (busy) throw new Error(`le port ${PORT} est déjà utilisé par un autre programme, abandon`);
  const profile = `${process.env.TMP ?? '.'}\\lignee-edge-probe`;
  const edge = spawn(
    EDGE,
    [
      '--headless=new',
      '--disable-gpu',
      '--no-first-run',
      '--disable-extensions',
      `--remote-debugging-port=${PORT}`,
      `--user-data-dir=${profile}`,
      `--window-size=${opt.width},${opt.height}`,
      'about:blank',
    ],
    { stdio: 'ignore' },
  );

  try {
    let target: { webSocketDebuggerUrl: string } | undefined;
    for (let i = 0; i < 50 && !target; i++) {
      await sleep(200);
      try {
        const list = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()) as {
          type: string;
          webSocketDebuggerUrl: string;
        }[];
        target = list.find((t) => t.type === 'page');
      } catch {
        target = undefined;
      }
    }
    if (!target) throw new Error('Edge headless injoignable');

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise<void>((resolve, reject) => {
      ws.addEventListener('open', () => resolve());
      ws.addEventListener('error', () => reject(new Error('websocket')));
    });
    const cdp = new Cdp(ws);
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: opt.width,
      height: opt.height,
      deviceScaleFactor: 1,
      mobile: false,
    });
    await cdp.send('Page.navigate', { url: opt.url });
    await sleep(opt.wait);

    for (const code of opt.evals) {
      const r = (await cdp.send('Runtime.evaluate', {
        expression: code,
        awaitPromise: true,
        returnByValue: true,
      })) as { result?: { value?: unknown }; exceptionDetails?: { text: string; exception?: { description?: string } } };
      if (r.exceptionDetails) {
        console.log(`eval ✗ ${code}\n   ${r.exceptionDetails.exception?.description ?? r.exceptionDetails.text}`);
      } else {
        console.log(`eval ✓ ${code}${r.result?.value !== undefined ? ` → ${JSON.stringify(r.result.value)}` : ''}`);
      }
      await sleep(400);
    }

    if (opt.out) {
      const shot = (await cdp.send('Page.captureScreenshot', { format: 'png' })) as { data: string };
      mkdirSync(dirname(opt.out), { recursive: true });
      writeFileSync(opt.out, Buffer.from(shot.data, 'base64'));
      console.log(`capture → ${opt.out}`);
    }

    const lines: string[] = [];
    for (const e of cdp.events) {
      if (e.method === 'Runtime.exceptionThrown') {
        const d = e.params.exceptionDetails as { text: string; exception?: { description?: string } };
        lines.push(`EXCEPTION ${d.exception?.description ?? d.text}`);
      } else if (e.method === 'Runtime.consoleAPICalled') {
        const p = e.params as { type: string; args: { value?: unknown; description?: string }[] };
        if (p.type === 'error' || p.type === 'warning') {
          lines.push(`${p.type.toUpperCase()} ${p.args.map((a) => a.value ?? a.description ?? '').join(' ')}`);
        }
      } else if (e.method === 'Log.entryAdded') {
        const entry = e.params.entry as { level: string; text: string; source: string };
        if (entry.level === 'error') lines.push(`LOG ${entry.source} ${entry.text}`);
      }
    }
    console.log(lines.length ? lines.join('\n') : 'console : aucune erreur');
    ws.close();
  } finally {
    edge.kill();
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
