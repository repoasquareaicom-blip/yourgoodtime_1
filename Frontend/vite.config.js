import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

const shareCrawlerPattern = /(whatsapp|facebookexternalhit|facebot|twitterbot|linkedinbot|slackbot|telegrambot|discordbot|pinterest|googlebot|bingbot|embedly|iframely|skypeuripreview|applebot|redditbot|quora|bitlybot)/i

function galleryShareOgPlugin() {
  return {
    name: 'gallery-share-og',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const match = req.url?.match(/^\/videos\/(\d+)(?:\/[^?]*)?(?:\?.*)?$/)
        if (!match || !shareCrawlerPattern.test(req.headers['user-agent'] || '')) {
          next()
          return
        }

        try {
          const upstream = await fetch(`http://127.0.0.1:8000/gallery/share/${match[1]}`, {
            headers: {
              'user-agent': req.headers['user-agent'] || 'WhatsApp',
            },
          })
          const html = await upstream.text()
          res.statusCode = upstream.status
          res.setHeader('content-type', 'text/html; charset=utf-8')
          res.end(html)
        } catch {
          next()
        }
      })
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), galleryShareOgPlugin()],
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
})
