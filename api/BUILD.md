# Vite Build · Policy

The compiled Laravel-side Vite bundle is **committed to this repo** at
`api/public/build/`. Server administrators do not need Node, npm, or Vite
installed to bring this application up.

## For server admins (production)

```bash
git pull origin main
sudo bash scripts/fix-poes-health-go-ug.sh   # or your local equivalent
```

That's it. **Do NOT run `npm install` or `npm run build`** — the assets are
already present. If you do run `npm run build` by accident, you'll see a
green message saying the bundle is already there, and the command will
exit 0 without doing any work (no node_modules required).

## For developers (when you edit `resources/css/app.css` or `resources/js/app.js`)

```bash
cd api
npm install
npm run build -- --force           # OR:  FORCE_BUILD=1 npm run build
git add public/build               # commit the new manifest + assets
git commit -m "Rebuild Laravel Vite bundle"
git push
```

## Why this approach

- Government servers often have no outbound internet, no Node, or strict
  package-manager policy. They cannot run npm safely.
- Vite produces deterministic, content-hashed filenames; the manifest
  maps Blade's `@vite()` lookups to the hashed files. As long as the
  triple (`manifest.json`, `assets/app-*.css`, `assets/app-*.js`) is in
  git, Laravel renders correctly on any clone.
- `package-lock.json` and `node_modules` stay out of the runtime path —
  only the output bytes do.

## What is in `api/public/build/`

```
api/public/build/
├── manifest.json                       (Vite → Laravel mapping)
└── assets/
    ├── app-<hash>.css                  (compiled Tailwind + custom CSS)
    └── app-<hash>.js                   (Alpine + bootstrap)
```

The hashes change on every rebuild that touches the inputs. Both the
manifest and the matching asset files must be committed together.
