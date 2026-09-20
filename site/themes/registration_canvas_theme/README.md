# Registration Canvas Theme

A generic Drupal Canvas layer between `registration_theme` (contrib) and a
site-specific theme:

```
registration_theme
  └─ registration_canvas_theme   (this theme)
       └─ <site theme>           (colours, fonts, logo, site-only components)
```

## What belongs here

- Canvas-ready single-directory components (SDCs) in `components/<name>/`, each
  with `<name>.component.yml`, `<name>.twig` and `<name>.css`.
- Canvas region and page templates.
- Rules that apply to every Canvas site.

## What does not

- Brand colours, fonts or logos. Set these in the site theme by overriding the
  `--rt-*` tokens.
- Edits to `registration_theme`. It is contrib and must not be changed in place.

## Conventions

- Vanilla CSS, no build step.
- Component classes use the `.c-<name>` prefix (`.c-heading__lead`).
- CSS uses `--rt-*` tokens only.
- Component props are simple (text, image, link, enum), because Canvas builds
  its editor form from them.
- Component IDs are namespaced by this theme, e.g.
  `sdc.registration_canvas_theme.heading`.
