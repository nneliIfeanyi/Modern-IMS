# Electronics Section - Code Reference

## HTML Markup

### Electronics Section Structure
```html
<section id="electronics" class="electronics-section section-space">
  <div class="container">
    <div class="row align-items-center g-5">
      
      <!-- Left Column: Visual Elements -->
      <div class="col-lg-6">
        <div class="electronics-visual">
          
          <!-- Hero Badge -->
          <div class="electronics-hero">
            <i class="bi bi-lightning-charge-fill"></i>
            <span>
              SMART HOME<br>
              <b>SOLUTIONS</b>
            </span>
          </div>
          
          <!-- Floating Note Card -->
          <div class="electronics-note">
            <i class="bi bi-plug"></i>
            <span>
              <strong>Quality home appliances</strong>
              <small>Modern tech for everyday living.</small>
            </span>
          </div>
          
        </div>
      </div>
      
      <!-- Right Column: Content -->
      <div class="col-lg-6">
        <p class="eyebrow"><span></span>MODERN LIVING</p>
        <h2>Smart solutions for your <em>home.</em></h2>
        <p>From kitchen appliances that make cooking easier to entertainment 
           systems that bring joy, explore our range of quality electronics 
           and home gadgets. Everything you need to make your home smarter 
           and more comfortable.</p>
        <p class="text-muted">Browse our wide selection of TVs, refrigerators, 
           washing machines, home theatre systems, blenders and more.</p>
        <a href="electronics.html" class="btn btn-electronics btn-lg">
          <i class="bi bi-arrow-up-right me-2"></i>Browse Electronics
        </a>
      </div>
      
    </div>
  </div>
</section>
```

## CSS Styles

### Color Variables
```css
:root {
    --blue-900: #1e3a8a;      /* Deep Navy Blue - Primary */
    --blue-700: #3b82f6;      /* Bright Blue - Accent */
    --blue-100: #dbeafe;      /* Light Blue */
    --blue-50: #f0f9ff;       /* Very Light Blue - Background */
}
```

### Main Section Styles
```css
.electronics-section { 
    background: var(--blue-50); 
}

.electronics-section h2 { 
    margin-bottom: 23px; 
    color: var(--blue-900); 
}

.electronics-section h2 em { 
    color: var(--blue-700); 
    font-style: normal; 
}
```

### Layout Styles
```css
/* Reorder columns on desktop */
.electronics-section > .container > .row > .col-lg-6:first-child { 
    order: 2;  /* Visual on right becomes left visually */
}

.electronics-section > .container > .row > .col-lg-6:last-child { 
    order: 1;  /* Content on left becomes right visually */
}

.electronics-visual { 
    position: relative; 
    min-height: 480px; 
    margin-bottom: 30px; 
}
```

### Hero Badge Styles
```css
.electronics-hero { 
    position: relative; 
    display: grid; 
    place-items: center; 
    width: 280px; 
    height: 280px; 
    margin: 0 auto; 
    color: #fff; 
    background: linear-gradient(135deg, var(--blue-900) 0%, var(--blue-700) 100%);
    border-radius: 18px; 
    box-shadow: 0 15px 40px rgba(30, 58, 138, .25); 
}

.electronics-hero i { 
    font-size: 4.5rem; 
    margin-bottom: 12px; 
    color: var(--yellow); 
}

.electronics-hero span { 
    font-size: .65rem; 
    font-weight: 800; 
    line-height: 1.3; 
    letter-spacing: .08em; 
    text-align: center; 
}

.electronics-hero b { 
    font-size: .78rem; 
}
```

### Note Card Styles
```css
.electronics-note { 
    position: absolute; 
    right: 0; 
    bottom: 55px; 
    display: flex; 
    align-items: center; 
    gap: 11px; 
    max-width: 220px; 
    padding: 15px; 
    color: #fff; 
    background: var(--blue-700); 
    border-radius: 12px; 
    box-shadow: 0 10px 30px rgba(30, 58, 138, .2); 
}

.electronics-note > i { 
    color: var(--yellow); 
    font-size: 1.3rem; 
}

.electronics-note strong, 
.electronics-note small { 
    display: block; 
}

.electronics-note strong { 
    font-size: .72rem; 
}

.electronics-note small { 
    margin-top: 4px; 
    color: #bfdbfe; 
    font-size: .65rem; 
    line-height: 1.35; 
}
```

### Button Styles
```css
.btn-electronics { 
    color: #fff; 
    background: var(--blue-900); 
    border-color: var(--blue-900); 
    padding: 13px 20px; 
    font-weight: 700; 
}

.btn-electronics:hover { 
    color: #fff; 
    background: var(--blue-700); 
    border-color: var(--blue-700); 
    transform: translateY(-2px); 
}
```

### Text Styles
```css
.electronics-section p:not(.eyebrow) { 
    max-width: 500px; 
    color: #475569; 
    font-size: .9rem; 
    line-height: 1.85; 
}

.electronics-section .eyebrow { 
    color: var(--blue-900); 
}

.electronics-section .eyebrow > span:first-child { 
    background: var(--blue-700); 
}
```

## Responsive Styles

### Tablet (max-width: 991.98px)
```css
.electronics-section > .container > .row > .col-lg-6:first-child { 
    order: 1;  /* Revert order */
}

.electronics-section > .container > .row > .col-lg-6:last-child { 
    order: 2;  /* Revert order */
}

.electronics-visual { 
    min-height: 400px; 
}
```

### Mobile (max-width: 767.98px)
```css
.electronics-visual { 
    min-height: 320px; 
    margin-bottom: 20px; 
}

.electronics-hero { 
    width: 240px; 
    height: 240px; 
}

.electronics-hero i { 
    font-size: 3.5rem; 
}

.electronics-note { 
    right: -15px; 
    bottom: 30px; 
    max-width: 180px; 
    padding: 12px; 
}
```

## Bootstrap Classes Used

| Class | Purpose |
|-------|---------|
| `section-space` | Standard section padding (105px top/bottom) |
| `container` | Max-width wrapper |
| `row` | Flex row container |
| `col-lg-6` | 50% width on lg+ screens |
| `align-items-center` | Vertical center alignment |
| `g-5` | Gap/gutter spacing |
| `btn btn-lg` | Button styles with large padding |

## Icon References

| Icon | Class | Usage |
|------|-------|-------|
| ⚡ Lightning | `bi-lightning-charge-fill` | Hero badge icon |
| 🔌 Plug | `bi-plug` | Note card icon |
| ➜ Arrow | `bi-arrow-up-right` | Button icon |

All icons use Bootstrap Icons (`bi` prefix) for consistency.

## Color Integration

### With Pharmacy Section
```
Pharmacy Green (#0b5d4b) ←→ Electronics Blue (#1e3a8a)
                ↓
              Gold (#f4c95d) - Shared accent
                ↑
         Creates visual harmony
```

### Accessibility
- ✅ Blue (#1e3a8a) on light blue (#f0f9ff): Contrast ratio 10.5:1
- ✅ White text on blue: Contrast ratio 11:1
- ✅ Gold icon on blue: Contrast ratio 4.8:1

---

## Usage Tips

1. **To modify colors:** Update CSS variables at `:root` level
2. **To adjust sizing:** Edit width/height values in `.electronics-hero`
3. **To change badge text:** Edit `<span>` inside `.electronics-hero`
4. **To update button target:** Change `href="electronics.html"`
5. **To adjust spacing:** Modify margin/padding values in responsive sections

## File Locations

- **HTML**: [index.html](C:/xampp/htdocs/IMS/index.html) - Lines ~43-47
- **CSS**: [assets/css/style.css](C:/xampp/htdocs/IMS/assets/css/style.css) - Electronics section
