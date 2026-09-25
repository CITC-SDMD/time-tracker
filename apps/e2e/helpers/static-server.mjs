// Serves the built dashboard (nuxt generate) the way nginx does in production: static files with
// the single-page fallback, and /api, /auth, /sanctum passed on to the Laravel API on the same origin.
import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2])
const port = Number(process.argv[3] ?? 3101)
const api = { host: '127.0.0.1', port: Number(process.argv[4] ?? 8001) }
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.map': 'application/json' }

http.createServer((req, res) => {
  if (/^\/(api|auth|sanctum)\//.test(req.url)) {
    const upstream = http.request({ ...api, path: req.url, method: req.method, headers: { ...req.headers, host: `${api.host}:${api.port}` } }, (r) => {
      res.writeHead(r.statusCode, r.headers)
      r.pipe(res)
    })
    upstream.on('error', () => { res.writeHead(502); res.end() })
    req.pipe(upstream)
    return
  }
  const clean = decodeURIComponent(req.url.split('?')[0])
  let file = path.join(root, clean)
  if (!file.startsWith(root) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) file = path.join(root, 'index.html')
  res.writeHead(200, { 'content-type': types[path.extname(file)] ?? 'application/octet-stream' })
  fs.createReadStream(file).pipe(res)
}).listen(port, '127.0.0.1', () => console.log(`dashboard on ${port}`))
