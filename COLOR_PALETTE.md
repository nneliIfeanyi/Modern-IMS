# ChyPaul Color Palette

## Existing Pharmacy Theme Colors
```
Primary Green:     #0b5d4b (var(--green-900))
Dark Green:        #073b31 (var(--green-950))
Teal Accent:       #0c9b88 (var(--teal))
Secondary Green:   #16836e (var(--green-700))
Gold/Yellow:       #f4c95d (var(--yellow))
Text (Ink):        #17322e (var(--ink))
Muted Text:        #6d7f7a (var(--muted))
Background:        #f8f8f2 (var(--cream))
Border:            #dfebe6 (var(--line))
```

## New Electronics Theme Colors
```
Primary Blue:      #1e3a8a (var(--blue-900))      ← Main color for electronics
Accent Blue:       #3b82f6 (var(--blue-700))      ← Interactive elements
Light Blue:        #dbeafe (var(--blue-100))      ← Light backgrounds
Very Light Blue:   #f0f9ff (var(--blue-50))       ← Section background
Gold/Yellow:       #f4c95d (var(--yellow))        ← Accent icons (reused)
```

## Color Usage

### Pharmacy Section
- **Buttons**: Green (#0b5d4b)
- **Headings**: Dark Green (#073b31)
- **Accents**: Gold (#f4c95d)
- **Background**: Cream (#f8f8f2)

### Electronics Section
- **Buttons**: Deep Blue (#1e3a8a) with hover to Bright Blue (#3b82f6)
- **Headings**: Deep Blue (#1e3a8a)
- **Accents**: Gold (#f4c95d) - lightning bolt icon
- **Background**: Very Light Blue (#f0f9ff)
- **Hero Badge**: Gradient Blue (#1e3a8a → #3b82f6)
- **Note Card**: Bright Blue (#3b82f6)

## Visual Contrast
- ✅ Deep Blue (#1e3a8a) provides excellent contrast on light blue backgrounds
- ✅ Blue theme visually distinct from pharmacy green while maintaining brand cohesion
- ✅ Gold accents tie both sections together
- ✅ Accessible color combinations meet WCAG AA standards

## Implementation Notes
All color variables are defined at the `:root` level in `assets/css/style.css`, making them easy to update globally if needed.

### CSS Variables Reference
```css
:root {
    /* Existing Colors */
    --green-950: #073b31;
    --green-900: #0b5d4b;
    --green-700: #16836e;
    --teal: #0c9b88;
    --yellow: #f4c95d;
    
    /* New Electronics Colors */
    --blue-900: #1e3a8a;
    --blue-700: #3b82f6;
    --blue-100: #dbeafe;
    --blue-50: #f0f9ff;
}
```
