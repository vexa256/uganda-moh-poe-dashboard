// Rasterise the SVG branding sources into the PNGs @capacitor/assets expects.
// Uses sharp (transitively installed via @capacitor/assets).
import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import sharp from 'sharp'

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = resolve(__dirname)

async function rasterize(svgName, pngName, size) {
  const svg = await readFile(resolve(root, svgName))
  const png = await sharp(svg, { density: 300, limitInputPixels: false })
    .resize(size, size, { fit: 'contain', background: { r: 11, g: 37, b: 69, alpha: 1 } })
    .png({ compressionLevel: 9 })
    .toBuffer()
  await writeFile(resolve(root, pngName), png)
  console.log(`wrote ${pngName} (${size}x${size}, ${(png.length / 1024).toFixed(1)} KiB)`)
}

async function rasterizeTransparent(svgName, pngName, size) {
  const svg = await readFile(resolve(root, svgName))
  const png = await sharp(svg, { density: 300, limitInputPixels: false })
    .resize(size, size, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ compressionLevel: 9 })
    .toBuffer()
  await writeFile(resolve(root, pngName), png)
  console.log(`wrote ${pngName} (${size}x${size}, transparent)`)
}

await rasterize('logo.svg',         'icon.png',        1024)
await rasterizeTransparent('logo.svg', 'icon-foreground.png', 1024)
await rasterize('splash.svg',       'splash.png',      2732)
await rasterize('splash-dark.svg',  'splash-dark.png', 2732)
console.log('OK — all sources rasterised.')
