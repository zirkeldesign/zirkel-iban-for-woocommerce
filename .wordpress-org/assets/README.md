# WordPress.org plugin directory assets

These are the icon and banner shown on the plugin's WordPress.org listing. They
are **not** part of the installed plugin — `.distignore` keeps the whole
`.wordpress-org/` directory out of the built zip.

## Files

| File | Purpose |
|---|---|
| `icon.svg` | Vector icon source — deployed as-is; WordPress.org prefers the SVG |
| `icon-256x256.png` | High-resolution icon (fallback for clients that ignore SVG) |
| `icon-128x128.png` | Low-resolution icon |
| `banner.svg` | Banner source — **not** deployed, only rasterized |
| `banner-1544x500.png` | Retina banner |
| `banner-772x250.png` | Standard banner |

## Design

One system with the other zirkel.design plugins (`privacy-captcha-for-cap`, `regex-validation-for-gravity-forms`): the plugin colour as a flat ground, a white form, and a check in a darker shade of the same colour. In the banner the plugin name and one line on the left, and on the right the product itself rather than decoration.

- **Colour:** `#1677c4` ground, check in `#0b2a47`. Deliberately not WooCommerce purple or Stripe indigo: the plugin has no business implying either brand's endorsement.
- **Glyph:** a transfer slip with its reference line, ticked off. The automatic match is what the product sells.
- **Banner scene:** what the customer sees at checkout (the IBAN and the order) with the "Matched" state.

Every white glyph that the check crosses has a gap painted in the ground colour behind the check, so the two never touch.

The IBAN in the banner is `DE89 3704 0044 0532 0130 00`, the standard documentation IBAN, not a real account.

## Regenerating the PNGs

Edit the SVG, then:

```bash
cd .wordpress-org/assets
rsvg-convert -w 256 -h 256 icon.svg -o icon-256x256.png
rsvg-convert -w 128 -h 128 icon.svg -o icon-128x128.png
rsvg-convert -w 772 -h 250 banner.svg -o banner-772x250.png
rsvg-convert -w 1544 -h 500 banner.svg -o banner-1544x500.png
```

`rsvg-convert` comes from `brew install librsvg`.

## Deployment

Pushing a change here to `main` triggers `.github/workflows/deploy-assets.yml`,
which commits the icon and banners to the plugin's SVN `assets/` directory. It
does not touch `trunk/` or `tags/`, so it needs no version bump or release.
